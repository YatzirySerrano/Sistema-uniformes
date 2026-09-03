<?php

namespace App\Servicios;

use App\Models\DetalleEntrega;
use App\Models\EntregaUniforme;
use App\Models\SaldoInventario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Consultas de reportes acotadas a un conjunto de empresas autorizadas. El
 * inventario se reporta por ALMACÉN; las entregas por sucursal (contexto del
 * colaborador). Todos los filtros son opcionales.
 */
class ServicioReportes
{
    /**
     * @param  array<string, mixed>  $filtros
     * @param  Collection<int, int>|array<int, int>  $empresaIds
     * @param  Collection<int, int>|array<int, int>  $sucursalesPermitidas
     * @return Builder<EntregaUniforme>
     */
    public function consultaEntregas($empresaIds, $sucursalesPermitidas, array $filtros): Builder
    {
        return EntregaUniforme::query()
            ->whereIn('empresa_id', $empresaIds)
            ->whereIn('sucursal_id', $sucursalesPermitidas)
            ->when($filtros['empresa_id'] ?? null, fn ($q, $v) => $q->where('empresa_id', $v))
            ->when($filtros['sucursal_id'] ?? null, fn ($q, $v) => $q->where('sucursal_id', $v))
            ->when($filtros['colaborador_id'] ?? null, fn ($q, $v) => $q->where('colaborador_id', $v))
            ->when($filtros['encargado_id'] ?? null, fn ($q, $v) => $q->where('encargado_id', $v))
            ->when($filtros['estado'] ?? null, fn ($q, $v) => $q->where('estado', $v))
            ->when(($filtros['firmado'] ?? null) === 'si', fn ($q) => $q->whereIn('estado', ['firmada', 'corregida']))
            ->when(($filtros['firmado'] ?? null) === 'no', fn ($q) => $q->where('estado', 'pendiente_firma'))
            ->when($filtros['desde'] ?? null, fn ($q, $v) => $q->whereDate('fecha_entrega', '>=', $v))
            ->when($filtros['hasta'] ?? null, fn ($q, $v) => $q->whereDate('fecha_entrega', '<=', $v))
            ->when($filtros['activo_id'] ?? null, fn ($q, $v) => $q->whereHas('detalles', fn ($d) => $d->where('activo_id', $v)))
            ->when($filtros['talla_id'] ?? null, fn ($q, $v) => $q->whereHas('detalles', fn ($d) => $d->where('talla_id', $v)))
            ->with(['empresa:id,nombre_comercial', 'colaborador:id,nombre_completo,numero_empleado', 'sucursal:id,nombre', 'encargado:id,name', 'detalles'])
            ->latest('fecha_entrega');
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @param  Collection<int, int>|array<int, int>  $empresaIds
     * @param  Collection<int, int>|array<int, int>  $sucursalesPermitidas
     * @return LengthAwarePaginator<int, EntregaUniforme>
     */
    public function entregasPaginadas($empresaIds, $sucursalesPermitidas, array $filtros, int $porPagina): LengthAwarePaginator
    {
        return $this->consultaEntregas($empresaIds, $sucursalesPermitidas, $filtros)
            ->paginate($porPagina)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @param  Collection<int, int>|array<int, int>  $empresaIds
     * @param  Collection<int, int>|array<int, int>  $sucursalesPermitidas
     * @return array{entregas: int, activos: int, pendientes_firma: int}
     */
    public function totalesEntregas($empresaIds, $sucursalesPermitidas, array $filtros): array
    {
        $ids = $this->consultaEntregas($empresaIds, $sucursalesPermitidas, $filtros)->reorder()->pluck('id');

        return [
            'entregas' => $ids->count(),
            'activos' => (int) DetalleEntrega::query()->whereIn('entrega_uniforme_id', $ids)->sum('cantidad'),
            'pendientes_firma' => EntregaUniforme::query()->whereIn('id', $ids)->where('estado', 'pendiente_firma')->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @param  Collection<int, int>|array<int, int>  $empresaIds
     * @param  Collection<int, int>|array<int, int>  $almacenesPermitidos
     * @return Builder<SaldoInventario>
     */
    public function consultaInventario($empresaIds, $almacenesPermitidos, array $filtros): Builder
    {
        return SaldoInventario::query()
            ->whereIn('empresa_id', $empresaIds)
            ->whereIn('almacen_id', $almacenesPermitidos)
            ->when($filtros['empresa_id'] ?? null, fn ($q, $v) => $q->where('empresa_id', $v))
            ->when($filtros['almacen_id'] ?? null, fn ($q, $v) => $q->where('almacen_id', $v))
            ->when($filtros['activo_id'] ?? null, fn ($q, $v) => $q->where('activo_id', $v))
            ->when(($filtros['solo_bajo_minimo'] ?? false), fn ($q) => $q->bajoMinimo())
            ->with(['empresa:id,nombre_comercial', 'almacen:id,nombre', 'activo:id,nombre', 'talla:id,valor'])
            ->orderBy('almacen_id');
    }
}
