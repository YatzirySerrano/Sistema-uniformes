<?php

namespace App\Servicios;

use App\Models\DetalleEntrega;
use App\Models\EntregaUniforme;
use App\Models\SaldoInventario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Consultas de reportes acotadas a una empresa y a un conjunto de sucursales
 * permitidas. Todos los filtros son opcionales.
 */
class ServicioReportes
{
    /**
     * @param  array<string, mixed>  $filtros
     * @param  Collection<int, int>|array<int, int>  $sucursalesPermitidas
     * @return Builder<EntregaUniforme>
     */
    public function consultaEntregas(int $empresaId, $sucursalesPermitidas, array $filtros): Builder
    {
        return EntregaUniforme::query()
            ->where('empresa_id', $empresaId)
            ->whereIn('sucursal_id', $sucursalesPermitidas)
            ->when($filtros['sucursal_id'] ?? null, fn ($q, $v) => $q->where('sucursal_id', $v))
            ->when($filtros['colaborador_id'] ?? null, fn ($q, $v) => $q->where('colaborador_id', $v))
            ->when($filtros['encargado_id'] ?? null, fn ($q, $v) => $q->where('encargado_id', $v))
            ->when($filtros['estado'] ?? null, fn ($q, $v) => $q->where('estado', $v))
            ->when(($filtros['firmado'] ?? null) === 'si', fn ($q) => $q->whereIn('estado', ['firmada', 'corregida']))
            ->when(($filtros['firmado'] ?? null) === 'no', fn ($q) => $q->where('estado', 'pendiente_firma'))
            ->when($filtros['desde'] ?? null, fn ($q, $v) => $q->whereDate('fecha_entrega', '>=', $v))
            ->when($filtros['hasta'] ?? null, fn ($q, $v) => $q->whereDate('fecha_entrega', '<=', $v))
            ->when($filtros['prenda_id'] ?? null, fn ($q, $v) => $q->whereHas('detalles', fn ($d) => $d->where('prenda_id', $v)))
            ->when($filtros['talla_id'] ?? null, fn ($q, $v) => $q->whereHas('detalles', fn ($d) => $d->where('talla_id', $v)))
            ->with(['colaborador:id,nombre_completo,numero_empleado', 'sucursal:id,nombre', 'encargado:id,name', 'detalles'])
            ->latest('fecha_entrega');
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @param  Collection<int, int>|array<int, int>  $sucursalesPermitidas
     * @return LengthAwarePaginator<int, EntregaUniforme>
     */
    public function entregasPaginadas(int $empresaId, $sucursalesPermitidas, array $filtros, int $porPagina): LengthAwarePaginator
    {
        return $this->consultaEntregas($empresaId, $sucursalesPermitidas, $filtros)
            ->paginate($porPagina)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @param  Collection<int, int>|array<int, int>  $sucursalesPermitidas
     * @return array{entregas: int, prendas: int, pendientes_firma: int}
     */
    public function totalesEntregas(int $empresaId, $sucursalesPermitidas, array $filtros): array
    {
        $ids = $this->consultaEntregas($empresaId, $sucursalesPermitidas, $filtros)->reorder()->pluck('id');

        return [
            'entregas' => $ids->count(),
            'prendas' => (int) DetalleEntrega::query()->whereIn('entrega_uniforme_id', $ids)->sum('cantidad'),
            'pendientes_firma' => EntregaUniforme::query()->whereIn('id', $ids)->where('estado', 'pendiente_firma')->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @param  Collection<int, int>|array<int, int>  $sucursalesPermitidas
     * @return Builder<SaldoInventario>
     */
    public function consultaInventario(int $empresaId, $sucursalesPermitidas, array $filtros): Builder
    {
        return SaldoInventario::query()
            ->where('empresa_id', $empresaId)
            ->whereIn('sucursal_id', $sucursalesPermitidas)
            ->when($filtros['sucursal_id'] ?? null, fn ($q, $v) => $q->where('sucursal_id', $v))
            ->when($filtros['prenda_id'] ?? null, fn ($q, $v) => $q->where('prenda_id', $v))
            ->when(($filtros['solo_bajo_minimo'] ?? false), fn ($q) => $q->bajoMinimo())
            ->with(['sucursal:id,nombre', 'prenda:id,nombre', 'talla:id,valor'])
            ->orderBy('sucursal_id');
    }
}
