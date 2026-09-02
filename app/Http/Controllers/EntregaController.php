<?php

namespace App\Http\Controllers;

use App\Acciones\CrearEntregaUniforme;
use App\Enums\EstadoEntrega;
use App\Http\Controllers\Concerns\ConEmpresaActiva;
use App\Http\Requests\Entregas\GuardarEntregaRequest;
use App\Models\EntregaUniforme;
use App\Models\SaldoInventario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EntregaController extends Controller
{
    use ConEmpresaActiva;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', EntregaUniforme::class);
        $empresa = $this->empresaActiva();

        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'sucursal_id' => ['nullable', 'integer'],
            'estado' => ['nullable', 'string'],
        ]);

        $sucursalesIds = $this->contexto()->sucursalesDisponibles()->pluck('id');

        $entregas = EntregaUniforme::query()
            ->where('empresa_id', $empresa->id)
            ->whereIn('sucursal_id', $sucursalesIds)
            ->when($filtros['buscar'] ?? null, fn ($q, $b) => $q->where(fn ($s) => $s
                ->where('folio', 'like', "%{$b}%")
                ->orWhereHas('colaborador', fn ($c) => $c->where('nombre_completo', 'like', "%{$b}%")->orWhere('numero_empleado', 'like', "%{$b}%"))))
            ->when($filtros['sucursal_id'] ?? null, fn ($q, $s) => $q->where('sucursal_id', $s))
            ->when($filtros['estado'] ?? null, fn ($q, $e) => $q->where('estado', $e))
            ->with(['colaborador:id,nombre_completo,numero_empleado', 'sucursal:id,nombre', 'encargado:id,name'])
            ->withCount('detalles')
            ->latest()
            ->paginate($this->porPagina())
            ->withQueryString()
            ->through(fn (EntregaUniforme $e): array => [
                'id' => $e->id,
                'folio' => $e->folio,
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
            'filtros' => $filtros,
            'sucursales' => $this->contexto()->sucursalesDisponibles()->map->only(['id', 'nombre'])->values(),
            'estados' => collect(EstadoEntrega::cases())->map(fn ($e): array => ['valor' => $e->value, 'etiqueta' => $e->etiqueta()]),
            'puedeCrear' => $request->user()->can('create', EntregaUniforme::class),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', EntregaUniforme::class);
        $empresa = $this->empresaActiva();

        return Inertia::render('Entregas/Crear', [
            'sucursales' => $this->contexto()->sucursalesDisponibles()->map->only(['id', 'nombre'])->values(),
            'colaboradores' => $empresa->colaboradores()->where('activo', true)
                ->orderBy('nombre_completo')
                ->get(['id', 'nombre_completo', 'numero_empleado', 'sucursal_id']),
            'activos' => $empresa->activos()->where('activo', true)->with('tallas:id,valor')->orderBy('nombre')->get()
                ->map(fn ($a): array => ['id' => $a->id, 'nombre' => $a->nombre, 'tallas' => $a->tallas->map->only(['id', 'valor'])->values()]),
        ]);
    }

    public function disponibilidad(Request $request): JsonResponse
    {
        $this->authorize('create', EntregaUniforme::class);
        $empresa = $this->empresaActiva();

        $datos = $request->validate(['sucursal_id' => ['required', 'integer']]);
        abort_unless($this->contexto()->puedeVerSucursal((int) $datos['sucursal_id']), 403);

        $saldos = SaldoInventario::query()
            ->where('empresa_id', $empresa->id)
            ->where('sucursal_id', $datos['sucursal_id'])
            ->get(['activo_id', 'talla_id', 'cantidad'])
            ->map(fn ($s): array => ['activo_id' => $s->activo_id, 'talla_id' => $s->talla_id, 'disponible' => (int) $s->cantidad]);

        return response()->json(['saldos' => $saldos]);
    }

    public function store(GuardarEntregaRequest $request, CrearEntregaUniforme $accion): RedirectResponse
    {
        $empresa = $this->empresaActiva();
        $datos = $request->validated();

        abort_unless($this->contexto()->puedeVerSucursal((int) $datos['sucursal_id']), 403, 'No tienes acceso a esa sucursal.');

        $entrega = $accion->ejecutar(
            $empresa->id,
            (int) $datos['sucursal_id'],
            (int) $datos['colaborador_id'],
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

    public function show(EntregaUniforme $entrega): Response
    {
        $this->authorize('view', $entrega);
        abort_unless($entrega->empresa_id === $this->empresaActiva()->id, 404);

        $entrega->load(['detalles.activo:id,nombre', 'detalles.talla:id,valor', 'colaborador:id,nombre_completo,numero_empleado,usuario_id', 'sucursal:id,nombre', 'encargado:id,name', 'acuse', 'correcciones.corregidaPor:id,name']);

        return Inertia::render('Entregas/Detalle', [
            'entrega' => [
                'id' => $entrega->id,
                'folio' => $entrega->folio,
                'estado' => $entrega->estado->value,
                'estado_etiqueta' => $entrega->estado->etiqueta(),
                'fecha_entrega' => $entrega->fecha_entrega->toDateString(),
                'confirmada_en' => $entrega->confirmada_en?->toIso8601String(),
                'notas' => $entrega->notas,
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
                'firmar' => request()->user()->can('firmar', $entrega),
                'corregir' => request()->user()->can('corregir', $entrega),
                'ver_pdf' => $entrega->acuse !== null && request()->user()->can('verPdf', $entrega->acuse),
                'ver_firma' => $entrega->acuse !== null && request()->user()->can('verFirma', $entrega->acuse),
            ],
        ]);
    }
}
