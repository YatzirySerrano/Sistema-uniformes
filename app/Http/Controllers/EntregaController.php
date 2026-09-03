<?php

namespace App\Http\Controllers;

use App\Acciones\CrearEntregaUniforme;
use App\Enums\EstadoEntrega;
use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Requests\Entregas\GuardarEntregaRequest;
use App\Models\Activo;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\EntregaUniforme;
use App\Models\SaldoInventario;
use App\Models\Talla;
use App\Servicios\ResolverAlmacenOperativo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Entregas de activos. La empresa se DERIVA del colaborador; la sucursal es la
 * del colaborador (contexto, no dimensión de stock). El almacén de origen se
 * resuelve con ResolverAlmacenOperativo::paraEmpresa. La reingeniería completa
 * de esta UI (selector de almacén, uniformes, serializados) es el Bloque E.
 */
class EntregaController extends Controller
{
    use ConEmpresa;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', EntregaUniforme::class);

        $idsAutorizadas = $this->idsEmpresasAutorizadas($request);

        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'empresa_id' => ['nullable', 'integer'],
            'estado' => ['nullable', 'string'],
        ]);

        $empresaFiltro = $this->empresaDelFiltro($request);

        $entregas = EntregaUniforme::query()
            ->whereIn('empresa_id', $idsAutorizadas)
            ->when($empresaFiltro !== null, fn ($q) => $q->where('empresa_id', $empresaFiltro->id))
            ->when($filtros['buscar'] ?? null, fn ($q, $b) => $q->where(fn ($s) => $s
                ->where('folio', 'like', "%{$b}%")
                ->orWhereHas('colaborador', fn ($c) => $c->where('nombre_completo', 'like', "%{$b}%")->orWhere('numero_empleado', 'like', "%{$b}%"))))
            ->when($filtros['estado'] ?? null, fn ($q, $e) => $q->where('estado', $e))
            ->with(['colaborador:id,nombre_completo,numero_empleado', 'sucursal:id,nombre', 'empresa:id,nombre_comercial', 'encargado:id,name'])
            ->withCount('detalles')
            ->latest()
            ->paginate($this->porPagina())
            ->withQueryString()
            ->through(fn (EntregaUniforme $e): array => [
                'id' => $e->id,
                'folio' => $e->folio,
                'empresa' => $e->empresa?->nombre_comercial,
                'colaborador' => $e->colaborador?->nombre_completo,
                'numero_empleado' => $e->colaborador?->numero_empleado,
                'sucursal' => $e->sucursal?->nombre,
                'encargado' => $e->encargado?->name,
                'estado' => $e->estado->value,
                'estado_etiqueta' => $e->estado->etiqueta(),
                'fecha_entrega' => $e->fecha_entrega->toDateString(),
                'renglones' => $e->detalles_count,
            ]);

        return Inertia::render('Entregas/Index', [
            'entregas' => $entregas,
            'filtros' => [...$filtros, 'empresa_id' => $empresaFiltro?->id],
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
            'estados' => collect(EstadoEntrega::cases())->map(fn ($e): array => ['valor' => $e->value, 'etiqueta' => $e->etiqueta()]),
            'puedeCrear' => $request->user()->can('create', EntregaUniforme::class),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', EntregaUniforme::class);

        $empresas = $this->empresasAutorizadas($request);

        $colaboradores = Colaborador::query()
            ->whereIn('empresa_id', $empresas->pluck('id'))
            ->where('activo', true)
            ->with(['sucursal:id,nombre', 'empresa:id,nombre_comercial'])
            ->orderBy('nombre_completo')
            ->get()
            ->map(fn (Colaborador $c): array => [
                'id' => $c->id,
                'nombre_completo' => $c->nombre_completo,
                'numero_empleado' => $c->numero_empleado,
                'empresa_id' => $c->empresa_id,
                'empresa' => $c->empresa?->nombre_comercial,
                'sucursal_id' => $c->sucursal_id,
                'sucursal' => $c->sucursal?->nombre,
            ]);

        $activosPorEmpresa = [];
        foreach ($empresas as $empresa) {
            $activosPorEmpresa[$empresa->id] = $this->activosDeEmpresa($empresa);
        }

        return Inertia::render('Entregas/Crear', [
            'colaboradores' => $colaboradores,
            'activosPorEmpresa' => $activosPorEmpresa,
        ]);
    }

    /**
     * @return array<int, array{id: int, nombre: string, tallas: array<int, mixed>}>
     */
    private function activosDeEmpresa(Empresa $empresa): array
    {
        return $empresa->activos()
            ->where('activo', true)
            ->where('tipo_control', 'cantidad')
            ->with('tallas:id,valor')
            ->orderBy('nombre')
            ->get()
            ->map(fn (Activo $a): array => [
                'id' => $a->id,
                'nombre' => $a->nombre,
                'tallas' => $a->tallas->map(fn (Talla $t): array => ['id' => $t->id, 'valor' => $t->valor])->all(),
            ])
            ->all();
    }

    public function disponibilidad(Request $request, ResolverAlmacenOperativo $resolver): JsonResponse
    {
        $this->authorize('create', EntregaUniforme::class);

        $datos = $request->validate(['colaborador_id' => ['required', 'integer']]);

        $colaborador = Colaborador::query()->find((int) $datos['colaborador_id']);

        if ($colaborador === null || ! $request->user()->puedeAccederEmpresa($colaborador->empresa_id)) {
            return response()->json(['saldos' => []]);
        }

        $almacen = $resolver->paraEmpresa($colaborador->empresa);

        $saldos = SaldoInventario::query()
            ->where('empresa_id', $colaborador->empresa_id)
            ->where('almacen_id', $almacen->id)
            ->get(['activo_id', 'talla_id', 'cantidad'])
            ->map(fn ($s): array => ['activo_id' => $s->activo_id, 'talla_id' => $s->talla_id, 'disponible' => (int) $s->cantidad]);

        return response()->json(['saldos' => $saldos, 'almacen' => ['id' => $almacen->id, 'nombre' => $almacen->nombre]]);
    }

    public function store(GuardarEntregaRequest $request, CrearEntregaUniforme $accion): RedirectResponse
    {
        $colaborador = Colaborador::findOrFail($request->integer('colaborador_id'));
        abort_unless($request->user()->puedeAccederEmpresa($colaborador->empresa_id), 403, 'No tienes acceso a la empresa de ese colaborador.');

        $datos = $request->validated();

        $entrega = $accion->ejecutar(
            $colaborador->empresa_id,
            $colaborador->sucursal_id,
            $colaborador->id,
            $request->user()->id,
            $datos['fecha_entrega'],
            $datos['items'],
            $datos['notas'] ?? null,
        );

        return to_route('entregas.show', $entrega)->with('toast', [
            'type' => 'success',
            'message' => "Entrega {$entrega->folio} registrada. Falta la firma de recepción.",
        ]);
    }

    public function show(Request $request, EntregaUniforme $entrega): Response
    {
        $this->authorize('view', $entrega);

        $entrega->load(['detalles.activo:id,nombre', 'detalles.talla:id,valor', 'colaborador:id,nombre_completo,numero_empleado,usuario_id', 'sucursal:id,nombre', 'empresa:id,nombre_comercial', 'encargado:id,name', 'acuse', 'correcciones.corregidaPor:id,name']);

        return Inertia::render('Entregas/Detalle', [
            'entrega' => [
                'id' => $entrega->id,
                'folio' => $entrega->folio,
                'estado' => $entrega->estado->value,
                'estado_etiqueta' => $entrega->estado->etiqueta(),
                'fecha_entrega' => $entrega->fecha_entrega->toDateString(),
                'confirmada_en' => $entrega->confirmada_en?->toIso8601String(),
                'notas' => $entrega->notas,
                'empresa' => $entrega->empresa?->nombre_comercial,
                'colaborador' => $entrega->colaborador?->only(['id', 'nombre_completo', 'numero_empleado']),
                'sucursal' => $entrega->sucursal?->nombre,
                'encargado' => $entrega->encargado?->name,
                'items' => $entrega->detalles->map(fn ($d): array => [
                    'activo' => $d->activo_nombre_snapshot,
                    'talla' => $d->talla_valor_snapshot,
                    'cantidad' => $d->cantidad,
                ]),
                'correcciones' => $entrega->correcciones->map(fn ($c): array => [
                    'id' => $c->id,
                    'motivo' => $c->motivo,
                    'por' => $c->corregidaPor?->name,
                    'fecha' => $c->created_at?->toIso8601String(),
                ]),
            ],
            'acuse' => $entrega->acuse === null ? null : [
                'id' => $entrega->acuse->id,
                'folio' => $entrega->acuse->folio,
                'firmado_en' => $entrega->acuse->firmado_en->toIso8601String(),
                'tiene_pdf' => $entrega->acuse->tienePdf(),
            ],
            'permisos' => [
                'firmar' => $request->user()->can('firmar', $entrega),
                'corregir' => $request->user()->can('corregir', $entrega),
                'ver_pdf' => $entrega->acuse !== null && $request->user()->can('verPdf', $entrega->acuse),
                'ver_firma' => $entrega->acuse !== null && $request->user()->can('verFirma', $entrega->acuse),
            ],
        ]);
    }
}
