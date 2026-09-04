<?php

namespace App\Http\Controllers;

use App\Acciones\CrearEntregaUniforme;
use App\Enums\EstadoEntrega;
use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Requests\Entregas\GuardarEntregaRequest;
use App\Models\Colaborador;
use App\Models\Devolucion;
use App\Models\EntregaUniforme;
use App\Models\SaldoInventario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Entregas de activos. La empresa y la sucursal se DERIVAN del colaborador
 * (contexto/histórico, no dimensión de stock); el almacén de origen se elige
 * explícitamente en el formulario (`ResolverAlmacenOperativo` sólo valida esa
 * elección o preselecciona cuando es inequívoca). La entrega combina activos
 * sueltos, unidades de seguimiento individual y conjuntos.
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

        return Inertia::render('Entregas/Crear');
    }

    /**
     * Existencias por cantidad (empresa+almacén, sin variante desglosada) para
     * mostrar un aviso de disponibilidad en el formulario. El backend siempre
     * revalida con bloqueo al registrar; esto es sólo UX.
     */
    public function disponibilidad(Request $request): JsonResponse
    {
        $this->authorize('create', EntregaUniforme::class);

        $datos = $request->validate([
            'empresa_id' => ['required', 'integer'],
            'almacen_id' => ['required', 'integer'],
        ]);

        if (! $request->user()->puedeAccederEmpresa((int) $datos['empresa_id'])) {
            return response()->json(['saldos' => []]);
        }

        $saldos = SaldoInventario::query()
            ->where('empresa_id', $datos['empresa_id'])
            ->where('almacen_id', $datos['almacen_id'])
            ->get(['activo_id', 'talla_id', 'cantidad'])
            ->map(fn ($s): array => ['activo_id' => $s->activo_id, 'talla_id' => $s->talla_id, 'disponible' => (int) $s->cantidad]);

        return response()->json(['saldos' => $saldos]);
    }

    /**
     * Búsqueda de entregas para originar una devolución (`Devoluciones/Crear`
     * sin `?entrega_id=` precargado). Devuelve entregas no anuladas de las
     * empresas autorizadas; el detalle de renglones pendientes se resuelve al
     * cargar la entrega concreta.
     */
    public function buscar(Request $request): JsonResponse
    {
        $this->authorize('create', Devolucion::class);

        $idsAutorizadas = $this->idsEmpresasAutorizadas($request);
        $termino = trim((string) $request->query('q', ''));

        $entregas = EntregaUniforme::query()
            ->whereIn('empresa_id', $idsAutorizadas)
            ->where('estado', '!=', EstadoEntrega::Anulada)
            ->when($termino !== '', fn ($q) => $q->where(fn ($s) => $s
                ->where('folio', 'like', "%{$termino}%")
                ->orWhereHas('colaborador', fn ($c) => $c->where('nombre_completo', 'like', "%{$termino}%")->orWhere('numero_empleado', 'like', "%{$termino}%"))))
            ->with(['colaborador:id,nombre_completo,numero_empleado', 'empresa:id,nombre_comercial'])
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (EntregaUniforme $e): array => [
                'id' => $e->id,
                'folio' => $e->folio,
                'colaborador' => $e->colaborador?->nombre_completo,
                'numero_empleado' => $e->colaborador?->numero_empleado,
                'empresa' => $e->empresa?->nombre_comercial,
                'fecha_entrega' => $e->fecha_entrega->toDateString(),
            ]);

        return response()->json(['entregas' => $entregas]);
    }

    public function store(GuardarEntregaRequest $request, CrearEntregaUniforme $accion): RedirectResponse
    {
        $colaborador = Colaborador::findOrFail($request->integer('colaborador_id'));
        abort_unless($request->user()->puedeAccederEmpresa($colaborador->empresa_id), 403, 'No tienes acceso a la empresa de ese colaborador.');

        $datos = $request->validated();

        $entrega = $accion->ejecutar(
            $colaborador->id,
            (int) $datos['almacen_id'],
            $request->user()->id,
            $datos['fecha_entrega'],
            $datos['activos'] ?? [],
            $datos['unidades'] ?? [],
            $datos['conjuntos'] ?? [],
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

        $entrega->load([
            'detalles.activo:id,nombre',
            'detalles.talla:id,valor',
            'detalles.unidadActivo:id,codigo,public_token',
            'colaborador:id,nombre_completo,numero_empleado,usuario_id',
            'sucursal:id,nombre',
            'empresa:id,nombre_comercial',
            'almacen:id,nombre',
            'encargado:id,name',
            'acuse',
            'correcciones.corregidaPor:id,name',
        ]);

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
                'almacen' => $entrega->almacen?->nombre,
                'colaborador' => $entrega->colaborador?->only(['id', 'nombre_completo', 'numero_empleado']),
                'sucursal' => $entrega->sucursal?->nombre,
                'encargado' => $entrega->encargado?->name,
                'items' => $entrega->detalles->map(fn ($d): array => [
                    'activo' => $d->activo_nombre_snapshot,
                    'talla' => $d->talla_valor_snapshot,
                    'cantidad' => $d->cantidad,
                    'unidad_codigo' => $d->unidadActivo?->codigo,
                    'conjunto' => $d->conjunto_nombre_snapshot,
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
                'devolver' => $entrega->estado !== EstadoEntrega::Anulada && $request->user()->can('create', Devolucion::class),
            ],
        ]);
    }
}
