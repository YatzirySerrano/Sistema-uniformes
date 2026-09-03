<?php

namespace App\Http\Controllers;

use App\Acciones\AjustarInventario;
use App\Acciones\RegistrarEntradaInventario;
use App\Enums\TipoControlActivo;
use App\Http\Controllers\Concerns\ConEmpresaActiva;
use App\Http\Requests\Activos\RegistrarEntradaInventarioRequest;
use App\Models\Activo;
use App\Models\CategoriaActivo;
use App\Models\SaldoInventario;
use App\Models\Talla;
use App\Models\TipoActivo;
use App\Servicios\ServicioInventario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inventario por ALMACÉN. El origen físico del stock es el almacén; la sucursal
 * dejó de ser fuente de existencias (ver "Asistente de migración de
 * existencias" para los saldos legacy).
 */
class InventarioController extends Controller
{
    use ConEmpresaActiva;

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('inventario.ver'), 403);
        $empresa = $this->empresaActiva();

        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'almacen_id' => ['nullable', 'integer'],
            'activo_id' => ['nullable', 'integer'],
            'tipo_activo_id' => ['nullable', 'integer'],
            'categoria_id' => ['nullable', 'integer'],
            'talla_id' => ['nullable', 'integer'],
            'control' => ['nullable', Rule::in(['cantidad', 'serializado'])],
            'estado_stock' => ['nullable', Rule::in(['bajo_minimo', 'sin_stock', 'con_stock'])],
        ]);

        $almacenesIds = $this->contexto()->almacenesDisponibles()->pluck('id');

        $saldos = SaldoInventario::query()
            ->where('empresa_id', $empresa->id)
            ->whereNotNull('almacen_id')
            ->whereIn('almacen_id', $almacenesIds)
            ->when($filtros['buscar'] ?? null, function (Builder $q, string $texto): void {
                $q->where(function (Builder $sub) use ($texto): void {
                    $sub->whereHas('activo', function (Builder $a) use ($texto): void {
                        $a->where('nombre', 'like', "%{$texto}%")
                            ->orWhere('codigo', 'like', "%{$texto}%")
                            ->orWhere('categoria', 'like', "%{$texto}%")
                            ->orWhereHas('tipoActivo', fn (Builder $t) => $t->where('nombre', 'like', "%{$texto}%"))
                            ->orWhereHas('categoriaActivo', fn (Builder $c) => $c->where('nombre', 'like', "%{$texto}%"));
                    })->orWhereHas('talla', fn (Builder $t) => $t->where('valor', 'like', "%{$texto}%"))
                        ->orWhereHas('almacen', fn (Builder $al) => $al->where('nombre', 'like', "%{$texto}%")->orWhere('codigo', 'like', "%{$texto}%"));
                });
            })
            ->when($filtros['almacen_id'] ?? null, fn (Builder $q, $v) => $q->where('almacen_id', $v))
            ->when($filtros['activo_id'] ?? null, fn (Builder $q, $v) => $q->where('activo_id', $v))
            ->when($filtros['talla_id'] ?? null, fn (Builder $q, $v) => $q->where('talla_id', $v))
            ->when($filtros['tipo_activo_id'] ?? null, fn (Builder $q, $v) => $q->whereHas('activo', fn (Builder $a) => $a->where('tipo_activo_id', $v)))
            ->when($filtros['categoria_id'] ?? null, fn (Builder $q, $v) => $q->whereHas('activo', fn (Builder $a) => $a->where('categoria_id', $v)))
            ->when($filtros['control'] ?? null, fn (Builder $q, $v) => $q->whereHas('activo', fn (Builder $a) => $a->where('tipo_control', $v)))
            ->when(($filtros['estado_stock'] ?? null) === 'bajo_minimo', fn (Builder $q) => $q->bajoMinimo())
            ->when(($filtros['estado_stock'] ?? null) === 'sin_stock', fn (Builder $q) => $q->where('cantidad', '<=', 0))
            ->when(($filtros['estado_stock'] ?? null) === 'con_stock', fn (Builder $q) => $q->where('cantidad', '>', 0))
            ->with(['almacen:id,nombre', 'activo:id,nombre,tipo_control', 'talla:id,valor'])
            ->orderBy('almacen_id')
            ->paginate($this->porPagina())
            ->withQueryString()
            ->through(fn (SaldoInventario $s): array => [
                'id' => $s->id,
                'almacen_id' => $s->almacen_id,
                'activo_id' => $s->activo_id,
                'talla_id' => $s->talla_id,
                'almacen' => $s->almacen?->nombre,
                'activo' => $s->activo?->nombre,
                'talla' => $s->talla?->valor,
                'control' => $s->activo?->tipo_control->value,
                'cantidad' => $s->cantidad,
                'minimo' => $s->minimo,
                'bajo_minimo' => $s->estaBajoMinimo(),
            ]);

        return Inertia::render('Inventario/Index', [
            'saldos' => $saldos,
            'filtros' => $filtros,
            'almacenes' => $this->contexto()->almacenesDisponibles()
                ->map(fn ($a): array => ['id' => $a->id, 'nombre' => $a->nombre, 'codigo' => $a->codigo, 'direccion' => $a->direccion])
                ->values(),
            'activos' => $empresa->activos()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'tiposActivo' => TipoActivo::query()->where('empresa_id', $empresa->id)->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'categorias' => CategoriaActivo::query()->where('empresa_id', $empresa->id)->where('activa', true)->orderBy('nombre')->get(['id', 'nombre']),
            'tallas' => $empresa->tallas()->ordenadas()->get(['id', 'valor']),
            'tiposControl' => TipoControlActivo::opciones(),
            'saldosLegacyPendientes' => SaldoInventario::query()->where('empresa_id', $empresa->id)->pendienteMigracion()->count(),
            'permisos' => [
                'entrada' => $request->user()->can('inventario.entrada'),
                'ajustar' => $request->user()->can('inventario.ajustar'),
                'minimos' => $request->user()->can('inventario.minimos'),
                'migrar' => $request->user()->can('inventario.migrar'),
            ],
        ]);
    }

    public function formularioEntrada(Request $request): Response
    {
        abort_unless($request->user()->can('inventario.entrada'), 403);
        $empresa = $this->empresaActiva();

        return Inertia::render('Inventario/Entrada', [
            'almacenes' => $this->contexto()->almacenesDisponibles()
                ->map(fn ($a): array => ['id' => $a->id, 'nombre' => $a->nombre, 'codigo' => $a->codigo, 'direccion' => $a->direccion])
                ->values(),
            // Sólo activos por cantidad: los serializados se registran unidad por
            // unidad en una fase posterior.
            'activos' => $empresa->activos()
                ->where('activo', true)
                ->where('tipo_control', 'cantidad')
                ->with(['tallas:id,valor', 'tipoActivo:id,nombre', 'categoriaActivo:id,nombre'])
                ->orderBy('nombre')
                ->get()
                ->map(fn (Activo $a): array => [
                    'id' => $a->id,
                    'nombre' => $a->nombre,
                    'codigo' => $a->codigo,
                    'tipo' => $a->tipoActivo?->nombre,
                    'categoria' => $a->categoriaActivo?->nombre,
                    'control' => $a->tipo_control->value,
                    'tallas' => $a->tallas->map(fn (Talla $t): array => ['id' => $t->id, 'valor' => $t->valor])->values(),
                ]),
        ]);
    }

    public function entrada(RegistrarEntradaInventarioRequest $request, RegistrarEntradaInventario $accion): RedirectResponse
    {
        $empresa = $this->empresaActiva();
        $datos = $request->validated();

        abort_unless($this->contexto()->puedeVerAlmacen((int) $datos['almacen_id']), 403, 'No tienes acceso a ese almacén.');

        $accion->ejecutar(
            $empresa->id,
            (int) $datos['almacen_id'],
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
            'almacen_id' => ['required', 'integer'],
            'activo_id' => ['required', 'integer'],
            'talla_id' => ['required', 'integer'],
            'existencia_objetivo' => ['required', 'integer', 'min:0', 'max:1000000'],
            'motivo' => ['required', 'string', 'max:255'],
        ]);

        abort_unless($this->contexto()->puedeVerAlmacen((int) $datos['almacen_id']), 403);

        $accion->ejecutar(
            $empresa->id,
            (int) $datos['almacen_id'],
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
            'almacen_id' => ['required', 'integer'],
            'activo_id' => ['required', 'integer'],
            'talla_id' => ['required', 'integer'],
            'minimo' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);

        abort_unless($this->contexto()->puedeVerAlmacen((int) $datos['almacen_id']), 403);

        $inventario->ajustarMinimo(
            $empresa->id,
            (int) $datos['almacen_id'],
            (int) $datos['activo_id'],
            (int) $datos['talla_id'],
            (int) $datos['minimo'],
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Mínimo actualizado.']);
    }
}
