<?php

namespace App\Servicios;

use App\Enums\DireccionMovimiento;
use App\Enums\EstadoVisibleUnidad;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\CategoriaActivo;
use App\Models\Colaborador;
use App\Models\Devolucion;
use App\Models\Empresa;
use App\Models\EntregaUniforme;
use App\Models\MovimientoInventario;
use App\Models\SaldoInventario;
use App\Models\UnidadActivo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Métricas del Dashboard para una empresa (siempre una a la vez: no hay
 * "empresa activa", el Panel elige y valida la empresa como cualquier otro
 * filtro). Todo se agrupa en SQL — nunca se trae el detalle a PHP para
 * contar/sumar ahí.
 */
class ServicioDashboard
{
    /**
     * @return array<string, mixed>
     */
    public function resumen(Empresa $empresa, Carbon $desde, Carbon $hasta, ?int $sucursalId, ?int $almacenId): array
    {
        return [
            'kpis' => [
                'colaboradores_activos' => Colaborador::query()
                    ->where('empresa_id', $empresa->id)->where('activo', true)
                    ->when($sucursalId, fn (Builder $q, int $v) => $q->where('sucursal_id', $v))
                    ->count(),
                'activos_activos' => Activo::query()
                    ->where('empresa_id', $empresa->id)->where('activo', true)->count(),
                'existencias_disponibles' => (int) SaldoInventario::query()
                    ->where('empresa_id', $empresa->id)
                    ->when($almacenId, fn (Builder $q, int $v) => $q->where('almacen_id', $v))
                    ->sum('cantidad'),
                'entregas_periodo' => $this->consultaEntregas($empresa, $desde, $hasta, $sucursalId, $almacenId)->count(),
                'devoluciones_periodo' => $this->consultaDevoluciones($empresa, $desde, $hasta, $sucursalId, $almacenId)->count(),
                'activos_stock_bajo' => SaldoInventario::query()
                    ->where('empresa_id', $empresa->id)
                    ->when($almacenId, fn (Builder $q, int $v) => $q->where('almacen_id', $v))
                    ->bajoMinimo()
                    ->count(),
                'almacenes_activos' => Almacen::query()->activos()->paraEmpresa($empresa->id)->count(),
                ...$this->kpisUnidades($empresa, $almacenId),
            ],
            'series' => [
                'entregas_por_periodo' => $this->serieDiaria(
                    $this->consultaEntregas($empresa, $desde, $hasta, $sucursalId, $almacenId),
                    'fecha_entrega', $desde, $hasta,
                ),
                'devoluciones_por_periodo' => $this->serieDiaria(
                    $this->consultaDevoluciones($empresa, $desde, $hasta, $sucursalId, $almacenId),
                    'fecha', $desde, $hasta,
                ),
                'movimientos_por_periodo' => $this->movimientosPorPeriodo($empresa, $desde, $hasta, $sucursalId, $almacenId),
                'unidades_por_estado' => $this->unidadesPorEstado($empresa, $almacenId),
                'existencias_por_almacen' => $this->existenciasPorAlmacen($empresa, $almacenId),
                'stock_por_categoria' => $this->stockPorCategoria($empresa, $almacenId),
            ],
            'entregas_recientes' => EntregaUniforme::query()
                ->where('empresa_id', $empresa->id)
                ->when($sucursalId, fn (Builder $q, int $v) => $q->where('sucursal_id', $v))
                ->when($almacenId, fn (Builder $q, int $v) => $q->where('almacen_id', $v))
                ->with(['colaborador:id,nombre_completo,numero_empleado', 'sucursal:id,nombre'])
                ->latest()
                ->limit(6)
                ->get()
                ->map(fn (EntregaUniforme $e): array => [
                    'id' => $e->id,
                    'folio' => $e->folio,
                    'colaborador' => $e->colaborador?->nombre_completo,
                    'sucursal' => $e->sucursal?->nombre,
                    'estado' => $e->estado->value,
                    'estado_etiqueta' => $e->estado->etiqueta(),
                    'fecha_entrega' => $e->fecha_entrega->toDateString(),
                ])->all(),
            'stock_bajo_detalle' => SaldoInventario::query()
                ->where('empresa_id', $empresa->id)
                ->when($almacenId, fn (Builder $q, int $v) => $q->where('almacen_id', $v))
                ->bajoMinimo()
                ->with(['activo:id,nombre', 'talla:id,valor', 'almacen:id,nombre'])
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
                    'cantidad' => $s->cantidad,
                    'minimo' => $s->minimo,
                ])->all(),
        ];
    }

    /**
     * @return Builder<EntregaUniforme>
     */
    private function consultaEntregas(Empresa $empresa, Carbon $desde, Carbon $hasta, ?int $sucursalId, ?int $almacenId): Builder
    {
        return EntregaUniforme::query()
            ->where('empresa_id', $empresa->id)
            ->whereDate('fecha_entrega', '>=', $desde->toDateString())
            ->whereDate('fecha_entrega', '<=', $hasta->toDateString())
            ->when($sucursalId, fn (Builder $q, int $v) => $q->where('sucursal_id', $v))
            ->when($almacenId, fn (Builder $q, int $v) => $q->where('almacen_id', $v));
    }

    /**
     * @return Builder<Devolucion>
     */
    private function consultaDevoluciones(Empresa $empresa, Carbon $desde, Carbon $hasta, ?int $sucursalId, ?int $almacenId): Builder
    {
        return Devolucion::query()
            ->where('empresa_id', $empresa->id)
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
     * @return array<string, int>
     */
    private function kpisUnidades(Empresa $empresa, ?int $almacenId): array
    {
        $totales = array_fill_keys(array_map(fn (EstadoVisibleUnidad $e) => $e->value, EstadoVisibleUnidad::cases()), 0);

        foreach ($this->unidadesAgrupadas($empresa, $almacenId) as $fila) {
            $totales[$fila['visible']->value] += $fila['total'];
        }

        return [
            'unidades_disponibles' => $totales[EstadoVisibleUnidad::Disponible->value],
            'unidades_asignadas' => $totales[EstadoVisibleUnidad::Asignado->value],
            'unidades_en_reparacion' => $totales[EstadoVisibleUnidad::Reparacion->value],
            'unidades_perdidas' => $totales[EstadoVisibleUnidad::Perdido->value],
            'unidades_robadas' => $totales[EstadoVisibleUnidad::Robado->value],
        ];
    }

    /**
     * @return array<int, array{estado: string, etiqueta: string, total: int}>
     */
    private function unidadesPorEstado(Empresa $empresa, ?int $almacenId): array
    {
        $totales = array_fill_keys(array_map(fn (EstadoVisibleUnidad $e) => $e->value, EstadoVisibleUnidad::cases()), 0);

        foreach ($this->unidadesAgrupadas($empresa, $almacenId) as $fila) {
            $totales[$fila['visible']->value] += $fila['total'];
        }

        return collect(EstadoVisibleUnidad::cases())
            ->map(fn (EstadoVisibleUnidad $e): array => [
                'estado' => $e->value,
                'etiqueta' => $e->etiqueta(),
                'total' => $totales[$e->value],
            ])->all();
    }

    /**
     * @return Collection<int, array{visible: EstadoVisibleUnidad, total: int}>
     */
    private function unidadesAgrupadas(Empresa $empresa, ?int $almacenId): Collection
    {
        return UnidadActivo::query()
            ->where('empresa_id', $empresa->id)
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
     * @return array<int, array{fecha: string, entradas: int, salidas: int}>
     */
    private function movimientosPorPeriodo(Empresa $empresa, Carbon $desde, Carbon $hasta, ?int $sucursalId, ?int $almacenId): array
    {
        $filas = MovimientoInventario::query()
            ->where('empresa_id', $empresa->id)
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
     * @return array<int, array{almacen: string, total: int}>
     */
    private function existenciasPorAlmacen(Empresa $empresa, ?int $almacenId): array
    {
        $totales = SaldoInventario::query()
            ->where('saldos_inventario.empresa_id', $empresa->id)
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
     * @return array<int, array{categoria: string, total: int}>
     */
    private function stockPorCategoria(Empresa $empresa, ?int $almacenId): array
    {
        $totales = SaldoInventario::query()
            ->where('saldos_inventario.empresa_id', $empresa->id)
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
