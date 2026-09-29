<?php

namespace App\Servicios;

use App\Enums\DireccionMovimiento;
use App\Enums\EstadoInventarioFisico;
use App\Enums\EstadoVisibleUnidad;
use App\Enums\SeccionDashboard;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\CategoriaActivo;
use App\Models\Colaborador;
use App\Models\Devolucion;
use App\Models\EntregaUniforme;
use App\Models\InventarioFisico;
use App\Models\MovimientoInventario;
use App\Models\SaldoInventario;
use App\Models\UnidadActivo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Métricas del Dashboard, agregadas sobre un CONJUNTO de empresas (siempre:
 * sin filtro de empresa son "todas las autorizadas del usuario"; con
 * filtro, es una lista de un solo id). No hay "empresa activa": el Panel
 * resuelve ese conjunto exactamente igual que cualquier otro filtro. Todo se
 * agrupa en SQL — nunca se trae el detalle a PHP para contar/sumar ahí.
 *
 * Semántica de fechas: los KPIs de ESTADO ACTUAL (colaboradores activos,
 * existencias, stock bajo, unidades, almacenes activos) nunca se acotan por
 * `desde`/`hasta` — falsearían la foto del momento. Sólo las métricas
 * TRANSACCIONALES (entregas, devoluciones, movimientos) respetan el rango.
 *
 * Qué se calcula lo deciden las `SeccionDashboard` autorizadas del usuario
 * (permisos efectivos, nunca su rol): ver `resumen()`.
 */
class ServicioDashboard
{
    /**
     * Sólo se EJECUTAN las consultas de las secciones recibidas (ya
     * autorizadas por `SeccionDashboard::autorizadasPara()`), y sólo sus
     * claves viajan en el resultado: una sección no autorizada no se consulta
     * ni se envía a Inertia — no es un `v-if` en Vue que oculta datos ya
     * calculados.
     *
     * @param  list<SeccionDashboard>  $secciones
     * @param  array<int, int>  $empresaIds
     * @return array{
     *     secciones: list<string>,
     *     kpis: array<string, int>,
     *     series: array<string, array<int, array<string, int|string>>>,
     *     entregas_recientes?: array<int, array<string, mixed>>,
     *     stock_bajo_detalle?: array<int, array<string, mixed>>,
     * }
     */
    public function resumen(array $secciones, array $empresaIds, Carbon $desde, Carbon $hasta, ?int $sucursalId, ?int $almacenId): array
    {
        $tiene = fn (SeccionDashboard $seccion): bool => in_array($seccion, $secciones, true);

        $resumen = [
            'secciones' => array_map(fn (SeccionDashboard $s): string => $s->value, $secciones),
            'kpis' => [],
            'series' => [],
        ];

        if ($tiene(SeccionDashboard::Colaboradores)) {
            $resumen['kpis']['colaboradores_activos'] = Colaborador::query()
                ->whereIn('empresa_id', $empresaIds)->where('activo', true)
                ->when($sucursalId, fn (Builder $q, int $v) => $q->where('sucursal_id', $v))
                ->count();
        }

        if ($tiene(SeccionDashboard::Activos)) {
            $resumen['kpis']['activos_activos'] = Activo::query()
                ->whereIn('empresa_id', $empresaIds)->where('activo', true)->count();
        }

        if ($tiene(SeccionDashboard::Inventario)) {
            $resumen['kpis']['existencias_disponibles'] = (int) SaldoInventario::query()
                ->whereIn('empresa_id', $empresaIds)
                ->when($almacenId, fn (Builder $q, int $v) => $q->where('almacen_id', $v))
                ->sum('cantidad');
            $resumen['kpis']['activos_stock_bajo'] = SaldoInventario::query()
                ->whereIn('empresa_id', $empresaIds)
                ->when($almacenId, fn (Builder $q, int $v) => $q->where('almacen_id', $v))
                ->bajoMinimo()
                ->count();
            $resumen['series']['movimientos_por_periodo'] = $this->movimientosPorPeriodo($empresaIds, $desde, $hasta, $sucursalId, $almacenId);
            $resumen['series']['existencias_por_almacen'] = $this->existenciasPorAlmacen($empresaIds, $almacenId);
            $resumen['series']['stock_por_categoria'] = $this->stockPorCategoria($empresaIds, $almacenId);
            $resumen['stock_bajo_detalle'] = $this->stockBajoDetalle($empresaIds, $almacenId);
        }

        if ($tiene(SeccionDashboard::Entregas)) {
            $consulta = $this->consultaEntregas($empresaIds, $desde, $hasta, $sucursalId, $almacenId);
            $resumen['kpis']['entregas_periodo'] = (clone $consulta)->count();
            $resumen['series']['entregas_por_periodo'] = $this->serieDiaria($consulta, 'fecha_entrega', $desde, $hasta);
            $resumen['entregas_recientes'] = $this->entregasRecientes($empresaIds, $sucursalId, $almacenId);
        }

        if ($tiene(SeccionDashboard::Devoluciones)) {
            $consulta = $this->consultaDevoluciones($empresaIds, $desde, $hasta, $sucursalId, $almacenId);
            $resumen['kpis']['devoluciones_periodo'] = (clone $consulta)->count();
            $resumen['series']['devoluciones_por_periodo'] = $this->serieDiaria($consulta, 'fecha', $desde, $hasta);
        }

        if ($tiene(SeccionDashboard::Almacenes)) {
            $resumen['kpis']['almacenes_activos'] = Almacen::query()->activos()->paraEmpresas($empresaIds)->count();
        }

        if ($tiene(SeccionDashboard::Unidades)) {
            // Un solo GROUP BY de `unidades_activo` alimenta KPIs y la serie.
            $unidadesAgrupadas = $this->unidadesAgrupadas($empresaIds, $almacenId);
            $resumen['kpis'] = [...$resumen['kpis'], ...$this->kpisUnidades($unidadesAgrupadas)];
            $resumen['series']['unidades_por_estado'] = $this->unidadesPorEstado($unidadesAgrupadas);
        }

        if ($tiene(SeccionDashboard::InventarioFisico)) {
            // Estado actual (no se acota por fechas ni por almacén: el listado
            // destino tampoco filtra por almacén, y la cifra debe coincidir).
            $resumen['kpis']['rondas_inventario_fisico_en_proceso'] = InventarioFisico::query()
                ->whereIn('empresa_id', $empresaIds)
                ->where('estado', EstadoInventarioFisico::EnProceso)
                ->count();
        }

        return $resumen;
    }

    /**
     * @param  array<int, int>  $empresaIds
     * @return array<int, array<string, mixed>>
     */
    private function entregasRecientes(array $empresaIds, ?int $sucursalId, ?int $almacenId): array
    {
        return EntregaUniforme::query()
            ->whereIn('empresa_id', $empresaIds)
            ->when($sucursalId, fn (Builder $q, int $v) => $q->where('sucursal_id', $v))
            ->when($almacenId, fn (Builder $q, int $v) => $q->where('almacen_id', $v))
            ->with(['colaborador:id,nombre_completo,numero_empleado', 'sucursal:id,nombre', 'empresa:id,nombre_comercial'])
            ->latest()
            ->limit(6)
            ->get()
            ->map(fn (EntregaUniforme $e): array => [
                'id' => $e->id,
                'folio' => $e->folio,
                'colaborador' => $e->colaborador?->nombre_completo,
                'sucursal' => $e->sucursal?->nombre,
                'empresa' => $e->empresa?->nombre_comercial,
                'estado' => $e->estado->value,
                'estado_etiqueta' => $e->estado->etiqueta(),
                'fecha_entrega' => $e->fecha_entrega->toDateString(),
            ])->all();
    }

    /**
     * @param  array<int, int>  $empresaIds
     * @return array<int, array<string, mixed>>
     */
    private function stockBajoDetalle(array $empresaIds, ?int $almacenId): array
    {
        return SaldoInventario::query()
            ->whereIn('empresa_id', $empresaIds)
            ->when($almacenId, fn (Builder $q, int $v) => $q->where('almacen_id', $v))
            ->bajoMinimo()
            ->with(['activo:id,nombre', 'talla:id,valor', 'almacen:id,nombre', 'empresa:id,nombre_comercial'])
            // `minimo` es UNSIGNED; `cantidad - minimo` puede dar negativo y
            // desborda BIGINT UNSIGNED en MariaDB (SQLSTATE[22003]).
            // `bajoMinimo()` ya garantiza cantidad <= minimo, así que
            // `minimo - cantidad` es siempre >= 0 y ordena igual (mayor
            // faltante primero).
            ->orderByRaw('(minimo - cantidad) DESC')
            ->limit(8)
            ->get()
            ->map(fn (SaldoInventario $s): array => [
                'activo' => $s->activo?->nombre,
                'talla' => $s->talla?->valor,
                'almacen' => $s->almacen?->nombre,
                'empresa' => $s->empresa?->nombre_comercial,
                'cantidad' => $s->cantidad,
                'minimo' => $s->minimo,
            ])->all();
    }

    /**
     * @param  array<int, int>  $empresaIds
     * @return Builder<EntregaUniforme>
     */
    private function consultaEntregas(array $empresaIds, Carbon $desde, Carbon $hasta, ?int $sucursalId, ?int $almacenId): Builder
    {
        return EntregaUniforme::query()
            ->whereIn('empresa_id', $empresaIds)
            ->whereDate('fecha_entrega', '>=', $desde->toDateString())
            ->whereDate('fecha_entrega', '<=', $hasta->toDateString())
            ->when($sucursalId, fn (Builder $q, int $v) => $q->where('sucursal_id', $v))
            ->when($almacenId, fn (Builder $q, int $v) => $q->where('almacen_id', $v));
    }

    /**
     * @param  array<int, int>  $empresaIds
     * @return Builder<Devolucion>
     */
    private function consultaDevoluciones(array $empresaIds, Carbon $desde, Carbon $hasta, ?int $sucursalId, ?int $almacenId): Builder
    {
        return Devolucion::query()
            ->whereIn('empresa_id', $empresaIds)
            ->whereDate('fecha', '>=', $desde->toDateString())
            ->whereDate('fecha', '<=', $hasta->toDateString())
            ->when($sucursalId, fn (Builder $q, int $v) => $q->where('sucursal_id', $v))
            ->when($almacenId, fn (Builder $q, int $v) => $q->where('almacen_id', $v));
    }

    /**
     * Cuenta por (estado, condicion) — cardinalidad acotada (3×5) — y colapsa
     * en PHP con la MISMA regla de `UnidadActivo::estadoVisible()`
     * (`EstadoVisibleUnidad::resolver()`), nunca una réplica en SQL.
     *
     * @param  Collection<int, array{visible: EstadoVisibleUnidad, total: int}>  $unidadesAgrupadas
     * @return array<string, int>
     */
    private function kpisUnidades(Collection $unidadesAgrupadas): array
    {
        $totales = $this->colapsarUnidadesPorVisible($unidadesAgrupadas);

        return [
            'unidades_disponibles' => $totales[EstadoVisibleUnidad::Disponible->value],
            'unidades_asignadas' => $totales[EstadoVisibleUnidad::Asignado->value],
            'unidades_en_reparacion' => $totales[EstadoVisibleUnidad::Reparacion->value],
            'unidades_perdidas' => $totales[EstadoVisibleUnidad::Perdido->value],
            'unidades_robadas' => $totales[EstadoVisibleUnidad::Robado->value],
        ];
    }

    /**
     * @param  Collection<int, array{visible: EstadoVisibleUnidad, total: int}>  $unidadesAgrupadas
     * @return array<int, array{estado: string, etiqueta: string, total: int}>
     */
    private function unidadesPorEstado(Collection $unidadesAgrupadas): array
    {
        $totales = $this->colapsarUnidadesPorVisible($unidadesAgrupadas);

        return collect(EstadoVisibleUnidad::cases())
            ->map(fn (EstadoVisibleUnidad $e): array => [
                'estado' => $e->value,
                'etiqueta' => $e->etiqueta(),
                'total' => $totales[$e->value],
            ])->all();
    }

    /**
     * @param  Collection<int, array{visible: EstadoVisibleUnidad, total: int}>  $unidadesAgrupadas
     * @return array<string, int>
     */
    private function colapsarUnidadesPorVisible(Collection $unidadesAgrupadas): array
    {
        $totales = array_fill_keys(array_map(fn (EstadoVisibleUnidad $e) => $e->value, EstadoVisibleUnidad::cases()), 0);

        foreach ($unidadesAgrupadas as $fila) {
            $totales[$fila['visible']->value] += $fila['total'];
        }

        return $totales;
    }

    /**
     * @param  array<int, int>  $empresaIds
     * @return Collection<int, array{visible: EstadoVisibleUnidad, total: int}>
     */
    private function unidadesAgrupadas(array $empresaIds, ?int $almacenId): Collection
    {
        return UnidadActivo::query()
            ->whereIn('empresa_id', $empresaIds)
            ->when($almacenId, fn (Builder $q, int $v) => $q->where('almacen_id', $v))
            ->selectRaw('estado, condicion, count(*) as total')
            ->groupBy('estado', 'condicion')
            ->get()
            ->map(fn (UnidadActivo $fila): array => [
                'visible' => EstadoVisibleUnidad::resolver($fila->estado, $fila->condicion),
                'total' => (int) $fila->getAttribute('total'),
            ]);
    }

    /**
     * @param  array<int, int>  $empresaIds
     * @return array<int, array{fecha: string, entradas: int, salidas: int}>
     */
    private function movimientosPorPeriodo(array $empresaIds, Carbon $desde, Carbon $hasta, ?int $sucursalId, ?int $almacenId): array
    {
        $filas = MovimientoInventario::query()
            ->whereIn('empresa_id', $empresaIds)
            ->whereBetween('ocurrido_en', [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()])
            ->when($sucursalId, fn (Builder $q, int $v) => $q->where('sucursal_id', $v))
            ->when($almacenId, fn (Builder $q, int $v) => $q->where('almacen_id', $v))
            ->selectRaw('DATE(ocurrido_en) as dia, direccion, sum(cantidad) as total')
            ->groupBy('dia', 'direccion')
            ->get()
            ->groupBy('dia');

        $serie = [];
        foreach ($this->rangoDeDias($desde, $hasta) as $dia) {
            $porDireccion = $filas->get($dia)?->mapWithKeys(fn (MovimientoInventario $fila): array => [
                $fila->direccion->value => (int) $fila->getAttribute('total'),
            ]) ?? collect();
            $serie[] = [
                'fecha' => $dia,
                'entradas' => (int) ($porDireccion[DireccionMovimiento::Entrada->value] ?? 0),
                'salidas' => (int) ($porDireccion[DireccionMovimiento::Salida->value] ?? 0),
            ];
        }

        return $serie;
    }

    /**
     * @param  Builder<EntregaUniforme>|Builder<Devolucion>  $consulta
     * @param  'fecha_entrega'|'fecha'  $columnaFecha
     * @return array<int, array{fecha: string, total: int}>
     */
    private function serieDiaria(Builder $consulta, string $columnaFecha, Carbon $desde, Carbon $hasta): array
    {
        $expresionFecha = match ($columnaFecha) {
            'fecha_entrega' => 'DATE(fecha_entrega)',
            'fecha' => 'DATE(fecha)',
        };

        $totalesPorDia = (clone $consulta)
            ->selectRaw("{$expresionFecha} as dia, count(*) as total")
            ->groupBy('dia')
            ->pluck('total', 'dia');

        return collect($this->rangoDeDias($desde, $hasta))
            ->map(fn (string $dia): array => ['fecha' => $dia, 'total' => (int) ($totalesPorDia[$dia] ?? 0)])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function rangoDeDias(Carbon $desde, Carbon $hasta): array
    {
        $dias = [];
        for ($fecha = $desde->copy()->startOfDay(); $fecha->lte($hasta); $fecha->addDay()) {
            $dias[] = $fecha->toDateString();
        }

        return $dias;
    }

    /**
     * @param  array<int, int>  $empresaIds
     * @return array<int, array{almacen: string, total: int}>
     */
    private function existenciasPorAlmacen(array $empresaIds, ?int $almacenId): array
    {
        $totales = SaldoInventario::query()
            ->whereIn('saldos_inventario.empresa_id', $empresaIds)
            ->when($almacenId, fn (Builder $q, int $v) => $q->where('saldos_inventario.almacen_id', $v))
            ->join('almacenes', 'almacenes.id', '=', 'saldos_inventario.almacen_id')
            ->selectRaw('almacenes.id, almacenes.nombre, sum(saldos_inventario.cantidad) as total')
            ->groupBy('almacenes.id', 'almacenes.nombre')
            ->orderByDesc('total')
            ->limit(8)
            ->get();

        return $totales
            ->map(fn ($fila): array => [
                'almacen' => (string) $fila->getAttribute('nombre'),
                'total' => (int) $fila->getAttribute('total'),
            ])
            ->all();
    }

    /**
     * @param  array<int, int>  $empresaIds
     * @return array<int, array{categoria: string, total: int}>
     */
    private function stockPorCategoria(array $empresaIds, ?int $almacenId): array
    {
        $totales = SaldoInventario::query()
            ->whereIn('saldos_inventario.empresa_id', $empresaIds)
            ->when($almacenId, fn (Builder $q, int $v) => $q->where('saldos_inventario.almacen_id', $v))
            ->join('activos', 'activos.id', '=', 'saldos_inventario.activo_id')
            ->selectRaw('activos.categoria_id, sum(saldos_inventario.cantidad) as total')
            ->groupBy('activos.categoria_id')
            ->orderByDesc('total')
            ->limit(8)
            ->get();

        $categoriaIds = $totales->map(fn ($fila) => $fila->getAttribute('categoria_id'))->filter()->all();

        $nombres = CategoriaActivo::query()
            ->whereIn('id', $categoriaIds)
            ->pluck('nombre', 'id');

        return $totales
            ->map(function ($fila) use ($nombres): array {
                $categoriaId = $fila->getAttribute('categoria_id');

                return [
                    'categoria' => $categoriaId ? (string) ($nombres[$categoriaId] ?? 'Sin categoría') : 'Sin categoría',
                    'total' => (int) $fila->getAttribute('total'),
                ];
            })->all();
    }
}
