<?php

namespace App\Servicios;

use App\Enums\EstadoVisibleUnidad;
use App\Models\DetalleDevolucion;
use App\Models\DetalleEntrega;
use App\Models\Devolucion;
use App\Models\EntregaUniforme;
use App\Models\SaldoInventario;
use App\Models\UnidadActivo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Consultas y MÉTRICAS de reportes acotadas a un conjunto de empresas
 * autorizadas. El inventario se reporta por ALMACÉN; las entregas por
 * sucursal (contexto del colaborador). Todos los filtros son opcionales.
 *
 * REGLA: `metricasEntregas()`/`metricasInventario()`/etc. son la ÚNICA fuente
 * de KPIs y series — `ReporteController` las llama igual para la pantalla,
 * el Excel y el PDF, así que los tres SIEMPRE cuadran entre sí. Nunca se
 * recalculan por separado.
 *
 * @phpstan-type FilaTopActivo array{activo: string, talla: ?string, piezas: int}
 * @phpstan-type FilaPorSucursal array{sucursal: string, piezas: int}
 *
 * `top_activos`/`por_sucursal` se devuelven como ARRAY PLANO (no
 * `Collection`): el genérico de `Collection` es invariante en PHPStan, así
 * que reenviar la misma `Collection<int, array{...}>` a través de varios
 * métodos (`kpisEntregas()`/`graficasEntregas()`) con un campo `?string`
 * dispara falsos positivos de tipo aunque las formas sean idénticas. Un
 * array plano no tiene ese problema y de cualquier forma es lo único que
 * viaja a Inertia/Excel/PDF — nunca se necesitó un objeto `Collection` río
 * abajo de `metricasEntregas()`.
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
            ->when($filtros['desde'] ?? null, fn ($q, $v) => $q->whereDate('fecha_entrega', '>=', $v))
            ->when($filtros['hasta'] ?? null, fn ($q, $v) => $q->whereDate('fecha_entrega', '<=', $v))
            ->when($filtros['activo_id'] ?? null, fn ($q, $v) => $q->whereHas('detalles', fn ($d) => $d->where('activo_id', $v)))
            ->when($filtros['talla_id'] ?? null, fn ($q, $v) => $q->whereHas('detalles', fn ($d) => $d->where('talla_id', $v)))
            ->with(['empresa:id,nombre_comercial', 'colaborador:id,nombre_completo,numero_empleado', 'sucursal:id,nombre', 'encargado:id,name', 'detalles', 'servicio:id,nombre,contrato_id', 'servicio.contrato:id,nombre'])
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
     * KPIs de Entregas + top de activos por PIEZAS (no por renglones), todo
     * agregado en SQL sobre el mismo filtro de `consultaEntregas()` — nunca
     * se trae la colección completa a PHP sólo para sumar/contar.
     *
     * @param  array<string, mixed>  $filtros
     * @param  Collection<int, int>|array<int, int>  $empresaIds
     * @param  Collection<int, int>|array<int, int>  $sucursalesPermitidas
     * @return array{
     *     entregas: int, renglones: int, piezas: int, colaboradores: int,
     *     tipos_activos: int,
     *     top_activos: array<int, FilaTopActivo>,
     *     por_sucursal: array<int, FilaPorSucursal>,
     * }
     */
    public function metricasEntregas($empresaIds, $sucursalesPermitidas, array $filtros): array
    {
        $base = $this->consultaEntregas($empresaIds, $sucursalesPermitidas, $filtros)->reorder();
        $entregaIds = (clone $base)->pluck('id');

        $detalles = DetalleEntrega::query()->whereIn('entrega_uniforme_id', $entregaIds);

        // Se agrupa por activo + VARIANTE (nunca sólo por activo): dos
        // renglones del mismo activo con tallas distintas ("Calzado de
        // Seguridad (32)" vs "Calzado de Seguridad (40)") son conceptos
        // entregados distintos y deben verse por separado en el top.
        $topActivos = (clone $detalles)
            ->selectRaw('activo_id, talla_id, MAX(activo_nombre_snapshot) as nombre, MAX(talla_valor_snapshot) as talla, SUM(cantidad) as piezas')
            ->groupBy('activo_id', 'talla_id')
            ->orderByDesc('piezas')
            ->limit(10)
            ->get()
            ->map(fn ($fila): array => [
                'activo' => (string) $fila->getAttribute('nombre'),
                'talla' => $this->valorNullableComoTexto($fila->getAttribute('talla')),
                'piezas' => (int) $fila->getAttribute('piezas'),
            ])->values()->all();

        $porSucursal = DetalleEntrega::query()
            ->join('entregas_uniformes', 'entregas_uniformes.id', '=', 'detalles_entrega.entrega_uniforme_id')
            ->join('sucursales', 'sucursales.id', '=', 'entregas_uniformes.sucursal_id')
            ->whereIn('entregas_uniformes.id', $entregaIds)
            ->selectRaw('sucursales.nombre as sucursal, SUM(detalles_entrega.cantidad) as piezas')
            ->groupBy('sucursales.id', 'sucursales.nombre')
            ->orderByDesc('piezas')
            ->get()
            ->map(fn ($fila): array => [
                'sucursal' => (string) $fila->getAttribute('sucursal'),
                'piezas' => (int) $fila->getAttribute('piezas'),
            ])->values()->all();

        return [
            'entregas' => $entregaIds->count(),
            'renglones' => (clone $detalles)->count(),
            'piezas' => (int) (clone $detalles)->sum('cantidad'),
            'colaboradores' => (clone $base)->distinct()->count('colaborador_id'),
            'tipos_activos' => (clone $detalles)->distinct()->count('activo_id'),
            'top_activos' => $topActivos,
            'por_sucursal' => $porSucursal,
        ];
    }

    /**
     * Piezas ENTREGADAS vs piezas DEVUELTAS por periodo (nunca folios/
     * renglones). Granularidad diaria si el rango efectivo es de 31 días o
     * menos; mensual si es mayor — el rango efectivo es el de los filtros
     * `desde`/`hasta` si se enviaron ambos, o si no, el que abarcan los datos
     * encontrados. Las devoluciones se acotan con el mismo alcance de
     * empresa/sucursal/fecha que las entregas (igual que
     * `ServicioDashboard::consultaDevoluciones()`), sin heredar filtros que
     * no les aplican (activo/talla/colaborador/encargado).
     *
     * @param  array<string, mixed>  $filtros
     * @param  Collection<int, int>|array<int, int>  $empresaIds
     * @param  Collection<int, int>|array<int, int>  $sucursalesPermitidas
     * @return array{granularidad: 'diaria'|'mensual', periodos: array<int, string>, entregadas: array<int, int>, devueltas: array<int, int>}
     */
    public function serieEntregasVsDevoluciones($empresaIds, $sucursalesPermitidas, array $filtros): array
    {
        $entregaIds = $this->consultaEntregas($empresaIds, $sucursalesPermitidas, $filtros)->reorder()->pluck('id');

        $filasEntregas = DetalleEntrega::query()
            ->join('entregas_uniformes', 'entregas_uniformes.id', '=', 'detalles_entrega.entrega_uniforme_id')
            ->whereIn('entregas_uniformes.id', $entregaIds)
            ->get(['entregas_uniformes.fecha_entrega as fecha', 'detalles_entrega.cantidad'])
            ->map(fn ($f): array => ['fecha' => Carbon::parse($f->getAttribute('fecha'))->toDateString(), 'cantidad' => (int) $f->getAttribute('cantidad')]);

        $devolucionesQuery = Devolucion::query()
            ->whereIn('empresa_id', $empresaIds)
            ->whereIn('sucursal_id', $sucursalesPermitidas)
            ->when($filtros['empresa_id'] ?? null, fn ($q, $v) => $q->where('empresa_id', $v))
            ->when($filtros['sucursal_id'] ?? null, fn ($q, $v) => $q->where('sucursal_id', $v))
            ->when($filtros['desde'] ?? null, fn ($q, $v) => $q->whereDate('fecha', '>=', $v))
            ->when($filtros['hasta'] ?? null, fn ($q, $v) => $q->whereDate('fecha', '<=', $v));

        $devolucionIds = $devolucionesQuery->pluck('id');

        $filasDevoluciones = DetalleDevolucion::query()
            ->join('devoluciones', 'devoluciones.id', '=', 'detalles_devolucion.devolucion_id')
            ->whereIn('devoluciones.id', $devolucionIds)
            ->get(['devoluciones.fecha as fecha', 'detalles_devolucion.cantidad'])
            ->map(fn ($f): array => ['fecha' => Carbon::parse($f->getAttribute('fecha'))->toDateString(), 'cantidad' => (int) $f->getAttribute('cantidad')]);

        $todasFechas = $filasEntregas->pluck('fecha')->merge($filasDevoluciones->pluck('fecha'));

        if ($todasFechas->isEmpty()) {
            return ['granularidad' => 'diaria', 'periodos' => [], 'entregadas' => [], 'devueltas' => []];
        }

        if (($filtros['desde'] ?? null) && ($filtros['hasta'] ?? null)) {
            $desde = Carbon::parse($filtros['desde']);
            $hasta = Carbon::parse($filtros['hasta']);
        } else {
            $desde = Carbon::parse($todasFechas->min());
            $hasta = Carbon::parse($todasFechas->max());
        }

        $granularidad = $desde->diffInDays($hasta) > 31 ? 'mensual' : 'diaria';
        $clave = fn (string $fecha): string => $granularidad === 'mensual' ? Carbon::parse($fecha)->format('Y-m') : $fecha;

        $entregadasPorClave = $filasEntregas->groupBy(fn (array $f): string => $clave($f['fecha']))->map(fn (Collection $g): int => (int) $g->sum('cantidad'));
        $devueltasPorClave = $filasDevoluciones->groupBy(fn (array $f): string => $clave($f['fecha']))->map(fn (Collection $g): int => (int) $g->sum('cantidad'));

        $periodos = $entregadasPorClave->keys()->merge($devueltasPorClave->keys())->unique()->sort()->values();

        return [
            'granularidad' => $granularidad,
            'periodos' => $periodos->all(),
            'entregadas' => $periodos->map(fn (string $k): int => (int) ($entregadasPorClave[$k] ?? 0))->all(),
            'devueltas' => $periodos->map(fn (string $k): int => (int) ($devueltasPorClave[$k] ?? 0))->all(),
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

    /**
     * KPIs + series de Inventario POR CANTIDAD (`saldos_inventario`) — nunca
     * mezcladas con unidades de seguimiento individual, que no tienen
     * "mínimo" ni "cantidad" comparables (ver `metricasUnidades()`).
     *
     * @param  array<string, mixed>  $filtros
     * @param  Collection<int, int>|array<int, int>  $empresaIds
     * @param  Collection<int, int>|array<int, int>  $almacenesPermitidos
     * @return array{
     *     piezas_disponibles: int, renglones_bajo_minimo: int, renglones_sin_existencias: int,
     *     por_almacen: Collection<int, array{almacen: string, piezas: int}>,
     *     riesgo_desabasto: Collection<int, array{activo: string, talla: mixed, disponible: int, minimo: int, faltante: int}>,
     * }
     */
    public function metricasInventario($empresaIds, $almacenesPermitidos, array $filtros): array
    {
        $base = $this->consultaInventario($empresaIds, $almacenesPermitidos, $filtros)->reorder();

        $porAlmacen = (clone $base)
            ->join('almacenes', 'almacenes.id', '=', 'saldos_inventario.almacen_id')
            ->selectRaw('almacenes.nombre as almacen, SUM(saldos_inventario.cantidad) as piezas')
            ->groupBy('almacenes.id', 'almacenes.nombre')
            ->orderByDesc('piezas')
            ->get()
            ->map(fn ($fila): array => [
                'almacen' => (string) $fila->getAttribute('almacen'),
                'piezas' => (int) $fila->getAttribute('piezas'),
            ]);

        // `bajoMinimo()` ya garantiza cantidad <= minimo, así que
        // `minimo - cantidad` nunca es negativo (evita desbordar la columna
        // UNSIGNED en MariaDB, igual que en `ServicioDashboard`).
        $riesgoDesabasto = (clone $base)
            ->bajoMinimo()
            ->with(['activo:id,nombre', 'talla:id,valor'])
            ->orderByRaw('(minimo - cantidad) DESC')
            ->limit(10)
            ->get()
            ->map(fn (SaldoInventario $s): array => $this->filaRiesgoDesabasto($s));

        return [
            'piezas_disponibles' => (int) (clone $base)->sum('cantidad'),
            'renglones_bajo_minimo' => (int) (clone $base)->bajoMinimo()->count(),
            'renglones_sin_existencias' => (int) (clone $base)->where('cantidad', 0)->count(),
            'por_almacen' => $porAlmacen,
            'riesgo_desabasto' => $riesgoDesabasto,
        ];
    }

    private function valorNullableComoTexto(mixed $valor): ?string
    {
        return $valor === null ? null : (string) $valor;
    }

    /**
     * @return array{activo: string, talla: mixed, disponible: int, minimo: int, faltante: int}
     */
    private function filaRiesgoDesabasto(SaldoInventario $s): array
    {
        return [
            'activo' => $s->activo->nombre,
            'talla' => $s->talla?->valor,
            'disponible' => (int) $s->cantidad,
            'minimo' => (int) $s->minimo,
            'faltante' => (int) $s->minimo - (int) $s->cantidad,
        ];
    }

    /**
     * Unidades de SEGUIMIENTO INDIVIDUAL agrupadas por su estado VISIBLE
     * (`EstadoVisibleUnidad::resolver()`, misma regla que el Dashboard —
     * reimplementada aquí en vez de depender de `ServicioDashboard` para no
     * acoplar Reportes al Panel). Cardinalidad acotada (estado × condición),
     * nunca se traen las unidades una por una.
     *
     * @param  array<string, mixed>  $filtros
     * @param  Collection<int, int>|array<int, int>  $empresaIds
     * @param  Collection<int, int>|array<int, int>  $almacenesPermitidos
     * @return array<string, int> clave = `EstadoVisibleUnidad::value`
     */
    public function metricasUnidades($empresaIds, $almacenesPermitidos, array $filtros): array
    {
        $agrupadas = UnidadActivo::query()
            ->whereIn('empresa_id', $empresaIds)
            ->whereIn('almacen_id', $almacenesPermitidos)
            ->when($filtros['almacen_id'] ?? null, fn ($q, $v) => $q->where('almacen_id', $v))
            ->when($filtros['activo_id'] ?? null, fn ($q, $v) => $q->where('activo_id', $v))
            ->selectRaw('estado, condicion, count(*) as total')
            ->groupBy('estado', 'condicion')
            ->get();

        $totales = array_fill_keys(array_map(fn (EstadoVisibleUnidad $e): string => $e->value, EstadoVisibleUnidad::cases()), 0);

        foreach ($agrupadas as $fila) {
            $visible = EstadoVisibleUnidad::resolver($fila->estado, $fila->condicion);
            $totales[$visible->value] += (int) $fila->getAttribute('total');
        }

        return $totales;
    }
}
