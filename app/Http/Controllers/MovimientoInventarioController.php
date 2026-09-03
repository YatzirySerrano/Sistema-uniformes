<?php

namespace App\Http\Controllers;

use App\Enums\TipoMovimiento;
use App\Http\Controllers\Concerns\ConEmpresa;
use App\Models\MovimientoInventario;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Historial de movimientos de inventario por empresa. Filtros por empresa y
 * almacén (búsqueda). `sucursal_id` sólo aparece como procedencia histórica.
 */
class MovimientoInventarioController extends Controller
{
    use ConEmpresa;

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('inventario.ver'), 403);

        $usuario = $request->user();
        $idsAutorizadas = $this->idsEmpresasAutorizadas($request);
        $empresaFiltro = $this->empresaDelFiltro($request);
        $idsScope = $empresaFiltro !== null ? collect([$empresaFiltro->id]) : $idsAutorizadas;

        $filtros = $request->validate([
            'almacen_id' => ['nullable', 'integer'],
            'activo_id' => ['nullable', 'integer'],
            'tipo' => ['nullable', 'string'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
        ]);

        $almacenesVisibles = $idsScope
            ->flatMap(fn (int $id): array => $this->acceso()->almacenesAutorizados($usuario, $id)->pluck('id')->all())
            ->unique()->values();

        $movimientos = MovimientoInventario::query()
            ->whereIn('empresa_id', $idsScope)
            ->where(function ($q) use ($almacenesVisibles): void {
                $q->whereIn('almacen_id', $almacenesVisibles)->orWhereNull('almacen_id');
            })
            ->when($filtros['almacen_id'] ?? null, fn ($q, $v) => $q->where('almacen_id', $v))
            ->when($filtros['activo_id'] ?? null, fn ($q, $v) => $q->where('activo_id', $v))
            ->when($filtros['tipo'] ?? null, fn ($q, $t) => $q->where('tipo', $t))
            ->when($filtros['desde'] ?? null, fn ($q, $d) => $q->whereDate('ocurrido_en', '>=', $d))
            ->when($filtros['hasta'] ?? null, fn ($q, $h) => $q->whereDate('ocurrido_en', '<=', $h))
            ->with(['empresa:id,nombre_comercial', 'almacen:id,nombre', 'sucursal:id,nombre', 'activo:id,nombre', 'talla:id,valor', 'realizadoPor:id,name'])
            ->latest('ocurrido_en')
            ->paginate($this->porPagina())
            ->withQueryString()
            ->through(fn (MovimientoInventario $m): array => [
                'id' => $m->id,
                'empresa' => $m->empresa?->nombre_comercial,
                'tipo' => $m->tipo->value,
                'tipo_etiqueta' => $m->tipo->etiqueta(),
                'direccion' => $m->direccion->value,
                'cantidad' => $m->cantidad,
                'existencia_anterior' => $m->existencia_anterior,
                'existencia_resultante' => $m->existencia_resultante,
                'almacen' => $m->almacen?->nombre,
                'sucursal' => $m->sucursal?->nombre,
                'activo' => $m->activo?->nombre,
                'talla' => $m->talla?->valor,
                'motivo' => $m->motivo,
                'realizado_por' => $m->realizadoPor?->name,
                'ocurrido_en' => $m->ocurrido_en->toIso8601String(),
            ]);

        return Inertia::render('Inventario/Movimientos', [
            'movimientos' => $movimientos,
            'filtros' => [...$filtros, 'empresa_id' => $empresaFiltro?->id],
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
            'almacenes' => $idsScope
                ->flatMap(fn (int $id): array => $this->acceso()->almacenesAutorizados($usuario, $id)->all())
                ->unique('id')->map->only(['id', 'nombre'])->values(),
            'tipos' => collect(TipoMovimiento::cases())->map(fn ($t): array => ['valor' => $t->value, 'etiqueta' => $t->etiqueta()]),
        ]);
    }
}
