<?php

namespace App\Http\Controllers;

use App\Enums\TipoMovimiento;
use App\Http\Controllers\Concerns\ConEmpresaActiva;
use App\Models\MovimientoInventario;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MovimientoInventarioController extends Controller
{
    use ConEmpresaActiva;

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('inventario.ver'), 403);
        $empresa = $this->empresaActiva();

        $filtros = $request->validate([
            'sucursal_id' => ['nullable', 'integer'],
            'activo_id' => ['nullable', 'integer'],
            'tipo' => ['nullable', 'string'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
        ]);

        $sucursalesIds = $this->contexto()->sucursalesDisponibles()->pluck('id');

        $movimientos = MovimientoInventario::query()
            ->where('empresa_id', $empresa->id)
            ->whereIn('sucursal_id', $sucursalesIds)
            ->when($filtros['sucursal_id'] ?? null, fn ($q, $s) => $q->where('sucursal_id', $s))
            ->when($filtros['activo_id'] ?? null, fn ($q, $p) => $q->where('activo_id', $p))
            ->when($filtros['tipo'] ?? null, fn ($q, $t) => $q->where('tipo', $t))
            ->when($filtros['desde'] ?? null, fn ($q, $d) => $q->whereDate('ocurrido_en', '>=', $d))
            ->when($filtros['hasta'] ?? null, fn ($q, $h) => $q->whereDate('ocurrido_en', '<=', $h))
            ->with(['sucursal:id,nombre', 'activo:id,nombre', 'talla:id,valor', 'realizadoPor:id,name'])
            ->latest('ocurrido_en')
            ->paginate($this->porPagina())
            ->withQueryString()
            ->through(fn (MovimientoInventario $m): array => [
                'id' => $m->id,
                'tipo' => $m->tipo->value,
                'tipo_etiqueta' => $m->tipo->etiqueta(),
                'direccion' => $m->direccion->value,
                'cantidad' => $m->cantidad,
                'existencia_anterior' => $m->existencia_anterior,
                'existencia_resultante' => $m->existencia_resultante,
                'sucursal' => $m->sucursal?->nombre,
                'activo' => $m->activo?->nombre,
                'talla' => $m->talla?->valor,
                'motivo' => $m->motivo,
                'realizado_por' => $m->realizadoPor?->name,
                'ocurrido_en' => $m->ocurrido_en->toIso8601String(),
            ]);

        return Inertia::render('Inventario/Movimientos', [
            'movimientos' => $movimientos,
            'filtros' => $filtros,
            'sucursales' => $this->contexto()->sucursalesDisponibles()->map->only(['id', 'nombre'])->values(),
            'activos' => $empresa->activos()->orderBy('nombre')->get(['id', 'nombre']),
            'tipos' => collect(TipoMovimiento::cases())->map(fn ($t): array => ['valor' => $t->value, 'etiqueta' => $t->etiqueta()]),
        ]);
    }
}
