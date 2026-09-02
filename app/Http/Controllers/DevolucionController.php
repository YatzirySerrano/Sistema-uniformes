<?php

namespace App\Http\Controllers;

use App\Acciones\RegistrarDevolucion;
use App\Enums\CondicionDevolucion;
use App\Http\Controllers\Concerns\ConEmpresaActiva;
use App\Models\Devolucion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DevolucionController extends Controller
{
    use ConEmpresaActiva;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Devolucion::class);
        $empresa = $this->empresaActiva();
        $sucursalesIds = $this->contexto()->sucursalesDisponibles()->pluck('id');

        $devoluciones = Devolucion::query()
            ->where('empresa_id', $empresa->id)
            ->whereIn('sucursal_id', $sucursalesIds)
            ->with(['colaborador:id,nombre_completo,numero_empleado', 'sucursal:id,nombre', 'registradaPor:id,name'])
            ->withCount('detalles')
            ->latest()
            ->paginate($this->porPagina())
            ->through(fn (Devolucion $d): array => [
                'id' => $d->id,
                'folio' => $d->folio,
                'colaborador' => $d->colaborador?->nombre_completo,
                'sucursal' => $d->sucursal?->nombre,
                'registrada_por' => $d->registradaPor?->name,
                'fecha' => $d->fecha->toDateString(),
                'renglones' => $d->detalles_count,
            ]);

        return Inertia::render('Devoluciones/Index', [
            'devoluciones' => $devoluciones,
            'puedeCrear' => $request->user()->can('create', Devolucion::class),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Devolucion::class);
        $empresa = $this->empresaActiva();

        return Inertia::render('Devoluciones/Crear', [
            'sucursales' => $this->contexto()->sucursalesDisponibles()->map->only(['id', 'nombre'])->values(),
            'colaboradores' => $empresa->colaboradores()->orderBy('nombre_completo')
                ->get(['id', 'nombre_completo', 'numero_empleado', 'sucursal_id']),
            'activos' => $empresa->activos()->with('tallas:id,valor')->orderBy('nombre')->get()
                ->map(fn ($a): array => ['id' => $a->id, 'nombre' => $a->nombre, 'tallas' => $a->tallas->map->only(['id', 'valor'])->values()]),
            'condiciones' => collect(CondicionDevolucion::cases())->map(fn ($c): array => ['valor' => $c->value, 'etiqueta' => $c->etiqueta()]),
        ]);
    }

    public function store(Request $request, RegistrarDevolucion $accion): RedirectResponse
    {
        $this->authorize('create', Devolucion::class);
        $empresa = $this->empresaActiva();

        $datos = $request->validate([
            'sucursal_id' => ['required', 'integer', Rule::exists('sucursales', 'id')->where(fn ($q) => $q->where('empresa_id', $empresa->id))],
            'colaborador_id' => ['required', 'integer', Rule::exists('colaboradores', 'id')->where(fn ($q) => $q->where('empresa_id', $empresa->id))],
            'entrega_uniforme_id' => ['nullable', 'integer'],
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'motivo' => ['nullable', 'string', 'max:255'],
            'notas' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.activo_id' => ['required', 'integer'],
            'items.*.talla_id' => ['required', 'integer'],
            'items.*.cantidad' => ['required', 'integer', 'min:1', 'max:1000'],
            'items.*.condicion' => ['required', Rule::enum(CondicionDevolucion::class)],
        ]);

        abort_unless($this->contexto()->puedeVerSucursal((int) $datos['sucursal_id']), 403);

        $devolucion = $accion->ejecutar(
            $empresa->id,
            (int) $datos['sucursal_id'],
            (int) $datos['colaborador_id'],
            $datos['entrega_uniforme_id'] ?? null,
            $datos['fecha'],
            $datos['items'],
            $request->user()->id,
            $datos['motivo'] ?? null,
            $datos['notas'] ?? null,
        );

        return to_route('devoluciones.index')->with('toast', [
            'type' => 'success', 'message' => "Devolución {$devolucion->folio} registrada.",
        ]);
    }
}
