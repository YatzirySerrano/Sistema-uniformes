<?php

namespace App\Http\Controllers;

use App\Acciones\AjustarInventario;
use App\Acciones\RegistrarEntradaInventario;
use App\Http\Controllers\Concerns\ConEmpresaActiva;
use App\Models\SaldoInventario;
use App\Servicios\ServicioInventario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventarioController extends Controller
{
    use ConEmpresaActiva;

    public function index(Request $request, ServicioInventario $inventario): Response
    {
        abort_unless($request->user()->can('inventario.ver'), 403);
        $empresa = $this->empresaActiva();

        $filtros = $request->validate([
            'sucursal_id' => ['nullable', 'integer'],
            'activo_id' => ['nullable', 'integer'],
            'solo_bajo_minimo' => ['nullable', 'boolean'],
        ]);

        $sucursalesIds = $this->contexto()->sucursalesDisponibles()->pluck('id');

        $saldos = SaldoInventario::query()
            ->where('empresa_id', $empresa->id)
            ->whereIn('sucursal_id', $sucursalesIds)
            ->when($filtros['sucursal_id'] ?? null, fn ($q, $s) => $q->where('sucursal_id', $s))
            ->when($filtros['activo_id'] ?? null, fn ($q, $p) => $q->where('activo_id', $p))
            ->when($filtros['solo_bajo_minimo'] ?? null, fn ($q) => $q->bajoMinimo())
            ->with(['sucursal:id,nombre', 'activo:id,nombre', 'talla:id,valor'])
            ->orderBy('sucursal_id')
            ->paginate($this->porPagina())
            ->withQueryString()
            ->through(fn ($s): array => [
                'id' => $s->id,
                'sucursal_id' => $s->sucursal_id,
                'activo_id' => $s->activo_id,
                'talla_id' => $s->talla_id,
                'sucursal' => $s->sucursal?->nombre,
                'activo' => $s->activo?->nombre,
                'talla' => $s->talla?->valor,
                'cantidad' => $s->cantidad,
                'minimo' => $s->minimo,
                'bajo_minimo' => $s->estaBajoMinimo(),
            ]);

        return Inertia::render('Inventario/Index', [
            'saldos' => $saldos,
            'filtros' => $filtros,
            'sucursales' => $this->contexto()->sucursalesDisponibles()->map->only(['id', 'nombre'])->values(),
            'activos' => $empresa->activos()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'permisos' => [
                'entrada' => $request->user()->can('inventario.entrada'),
                'ajustar' => $request->user()->can('inventario.ajustar'),
                'minimos' => $request->user()->can('inventario.minimos'),
            ],
        ]);
    }

    public function formularioEntrada(Request $request): Response
    {
        abort_unless($request->user()->can('inventario.entrada'), 403);
        $empresa = $this->empresaActiva();

        return Inertia::render('Inventario/Entrada', [
            'sucursales' => $this->contexto()->sucursalesDisponibles()->map->only(['id', 'nombre'])->values(),
            'activos' => $empresa->activos()->where('activo', true)->with('tallas:id,valor')->orderBy('nombre')->get()
                ->map(fn ($a): array => ['id' => $a->id, 'nombre' => $a->nombre, 'tallas' => $a->tallas->map->only(['id', 'valor'])]),
        ]);
    }

    public function entrada(Request $request, RegistrarEntradaInventario $accion): RedirectResponse
    {
        abort_unless($request->user()->can('inventario.entrada'), 403);
        $empresa = $this->empresaActiva();

        $datos = $request->validate([
            'sucursal_id' => ['required', 'integer'],
            'motivo' => ['required', 'string', 'max:255'],
            'notas' => ['nullable', 'string', 'max:1000'],
            'carga_inicial' => ['boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.activo_id' => ['required', 'integer'],
            'items.*.talla_id' => ['required', 'integer'],
            'items.*.cantidad' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);

        abort_unless($this->contexto()->puedeVerSucursal((int) $datos['sucursal_id']), 403, 'No tienes acceso a esa sucursal.');

        $accion->ejecutar(
            $empresa->id,
            (int) $datos['sucursal_id'],
            $datos['items'],
            $datos['motivo'],
            $request->user()->id,
            $request->boolean('carga_inicial'),
            $datos['notas'] ?? null,
        );

        return to_route('inventario.index')->with('toast', ['type' => 'success', 'message' => 'Entrada de inventario registrada.']);
    }

    public function ajuste(Request $request, AjustarInventario $accion): RedirectResponse
    {
        abort_unless($request->user()->can('inventario.ajustar'), 403);
        $empresa = $this->empresaActiva();

        $datos = $request->validate([
            'sucursal_id' => ['required', 'integer'],
            'activo_id' => ['required', 'integer'],
            'talla_id' => ['required', 'integer'],
            'existencia_objetivo' => ['required', 'integer', 'min:0', 'max:1000000'],
            'motivo' => ['required', 'string', 'max:255'],
        ]);

        abort_unless($this->contexto()->puedeVerSucursal((int) $datos['sucursal_id']), 403);

        $accion->ejecutar(
            $empresa->id,
            (int) $datos['sucursal_id'],
            (int) $datos['activo_id'],
            (int) $datos['talla_id'],
            (int) $datos['existencia_objetivo'],
            $datos['motivo'],
            $request->user()->id,
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Ajuste de existencias registrado.']);
    }

    public function minimos(Request $request, ServicioInventario $inventario): RedirectResponse
    {
        abort_unless($request->user()->can('inventario.minimos'), 403);
        $empresa = $this->empresaActiva();

        $datos = $request->validate([
            'sucursal_id' => ['required', 'integer'],
            'activo_id' => ['required', 'integer'],
            'talla_id' => ['required', 'integer'],
            'minimo' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);

        abort_unless($this->contexto()->puedeVerSucursal((int) $datos['sucursal_id']), 403);

        $inventario->ajustarMinimo(
            $empresa->id,
            (int) $datos['sucursal_id'],
            (int) $datos['activo_id'],
            (int) $datos['talla_id'],
            (int) $datos['minimo'],
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Mínimo actualizado.']);
    }
}
