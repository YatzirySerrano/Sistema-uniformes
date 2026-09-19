<?php

namespace App\Servicios;

use App\Enums\CondicionDevolucion;
use App\Enums\EstadoDevolucion;
use App\Enums\EstadoEntrega;
use App\Enums\TipoMovimiento;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\CondicionInventario;
use App\Models\DetalleDevolucion;
use App\Models\DetalleEntrega;
use App\Models\SaldoInventario;
use App\Models\Talla;

/**
 * Estado ACTUAL (no histórico crudo) de un activo por CANTIDAD, agregado por
 * almacén + variante: Disponible / Asignado / Dañado / Baja / Robo-extravío.
 * Sólo lectura — nunca escribe inventario (eso sigue siendo únicamente
 * `ServicioInventario`). No aplica a activos con seguimiento individual
 * (`UnidadActivo` ya tiene su propio estado por unidad, ver `resumenUnidades`
 * en `ActivoController`).
 *
 * Semántica (confirmada por auditoría de código antes de implementar, no
 * inventada):
 * - Disponible = `SaldoInventario.cantidad` (entregable ahora mismo; ya
 *   refleja cualquier pieza marcada Dañado/Baja/Robo-extravío, porque
 *   `MarcarCondicionInventario`/`RestaurarCondicionInventario` la ajustan vía
 *   `ServicioInventario::registrarMovimiento()`, nunca aparte).
 * - Asignado = entregado (entregas firmada/corregida) − devuelto (CUALQUIER
 *   condición, devolución confirmada). Una pieza devuelta ya no está en
 *   posesión del colaborador sin importar en qué condición volvió.
 * - Dañado / Baja = suma de DOS fuentes independientes, nunca se pisan entre
 *   sí: (1) histórico de `detalles_devolucion.cantidad` por `condicion`, sólo
 *   de devoluciones confirmadas (se escribe una única vez, nunca se
 *   modifica); (2) `condiciones_inventario` (marcado directo desde stock,
 *   fuera del flujo de devolución — `MarcarCondicionInventario`/
 *   `RestaurarCondicionInventario`), neto de "marcar" menos "restaurar" para
 *   Dañado (Baja es terminal, sin restauración).
 * - Robo / extravío = SÓLO `condiciones_inventario` (nunca una devolución:
 *   no se puede "devolver" algo reportado como robado o extraviado). Es
 *   terminal, igual que Baja: no hay "restaurar" desde ahí.
 */
class ServicioEstadoInventario
{
    /**
     * @return array{
     *     resumen: array{disponible: int, asignado: int, danado: int, baja: int, robo_extravio: int},
     *     desglose: list<array{
     *         almacen_id: int,
     *         almacen: string,
     *         variantes: list<array{
     *             talla_id: int|null,
     *             talla: string|null,
     *             disponible: int,
     *             asignado: int,
     *             danado: int,
     *             baja: int,
     *             robo_extravio: int
     *         }>
     *     }>
     * }
     */
    public function porActivo(Activo $activo): array
    {
        /** @var array<string, array{almacen_id: int, talla_id: int|null, disponible: int, asignado: int, danado: int, baja: int, robo_extravio: int}> $filas */
        $filas = [];

        // --- Disponible: saldo actual (una sola fuente, ServicioInventario
        // es quien la mantiene consistente al escribir). ---
        foreach (
            SaldoInventario::query()
                ->where('empresa_id', $activo->empresa_id)
                ->where('activo_id', $activo->id)
                ->get(['almacen_id', 'talla_id', 'cantidad']) as $saldo
        ) {
            $almacenId = (int) $saldo->getAttribute('almacen_id');
            $tallaId = $this->tallaId($saldo->getAttribute('talla_id'));
            $fila = &$this->fila($filas, $almacenId, $tallaId);
            $fila['disponible'] += (int) $saldo->getAttribute('cantidad');
            unset($fila);
        }

        // --- Entregado (entregas firmada/corregida): base de "Asignado". ---
        foreach (
            DetalleEntrega::query()
                ->join('entregas_uniformes', 'entregas_uniformes.id', '=', 'detalles_entrega.entrega_uniforme_id')
                ->where('detalles_entrega.activo_id', $activo->id)
                ->whereNull('detalles_entrega.unidad_activo_id')
                ->whereIn('entregas_uniformes.estado', [EstadoEntrega::Firmada->value, EstadoEntrega::Corregida->value])
                ->selectRaw('entregas_uniformes.almacen_id as almacen_id, detalles_entrega.talla_id as talla_id, SUM(detalles_entrega.cantidad) as total')
                ->groupBy('entregas_uniformes.almacen_id', 'detalles_entrega.talla_id')
                ->get() as $entregado
        ) {
            $almacenId = (int) $entregado->getAttribute('almacen_id');
            $tallaId = $this->tallaId($entregado->getAttribute('talla_id'));
            $fila = &$this->fila($filas, $almacenId, $tallaId);
            $fila['asignado'] += (int) $entregado->getAttribute('total');
            unset($fila);
        }

        // --- Devuelto (devolución confirmada), desglosado por condición: se
        // resta de Asignado (cualquier condición) y alimenta Dañado/Baja. ---
        foreach (
            DetalleDevolucion::query()
                ->join('devoluciones', 'devoluciones.id', '=', 'detalles_devolucion.devolucion_id')
                ->where('detalles_devolucion.activo_id', $activo->id)
                ->whereNull('detalles_devolucion.unidad_activo_id')
                ->where('devoluciones.estado', EstadoDevolucion::Confirmada->value)
                ->selectRaw(
                    'devoluciones.almacen_id as almacen_id, detalles_devolucion.talla_id as talla_id, '
                    .'SUM(detalles_devolucion.cantidad) as devuelto_total, '
                    .'SUM(CASE WHEN detalles_devolucion.condicion = ? THEN detalles_devolucion.cantidad ELSE 0 END) as danado, '
                    .'SUM(CASE WHEN detalles_devolucion.condicion = ? THEN detalles_devolucion.cantidad ELSE 0 END) as baja',
                    [CondicionDevolucion::Danado->value, CondicionDevolucion::Baja->value],
                )
                ->groupBy('devoluciones.almacen_id', 'detalles_devolucion.talla_id')
                ->get() as $devuelto
        ) {
            $almacenId = (int) $devuelto->getAttribute('almacen_id');
            $tallaId = $this->tallaId($devuelto->getAttribute('talla_id'));
            $fila = &$this->fila($filas, $almacenId, $tallaId);
            // "Asignado" nunca baja de 0: una devolución sólo puede liberar
            // lo que realmente estaba entregado.
            $fila['asignado'] = max(0, $fila['asignado'] - (int) $devuelto->getAttribute('devuelto_total'));
            $fila['danado'] += (int) $devuelto->getAttribute('danado');
            $fila['baja'] += (int) $devuelto->getAttribute('baja');
            unset($fila);
        }

        // --- Condición marcada directamente desde stock (fuera del flujo de
        // devolución): neto de "marcar" (Incidencia/Baja) menos "restaurar"
        // (Recuperacion, sólo aplica a Dañado — Baja y Robo/extravío son
        // terminales). Se SUMA a lo que ya aportaron las devoluciones, nunca
        // lo reemplaza: ambas fuentes son reales y distintas. Dañado y
        // Robo/extravío comparten `TipoMovimiento::Incidencia`; la columna
        // `condicion` es lo único que los distingue aquí. ---
        foreach (
            CondicionInventario::query()
                ->where('empresa_id', $activo->empresa_id)
                ->where('activo_id', $activo->id)
                ->selectRaw(
                    'almacen_id, talla_id, '
                    .'SUM(CASE WHEN condicion = ? AND tipo = ? THEN cantidad WHEN condicion = ? AND tipo = ? THEN -cantidad ELSE 0 END) as danado, '
                    .'SUM(CASE WHEN condicion = ? AND tipo = ? THEN cantidad ELSE 0 END) as baja, '
                    .'SUM(CASE WHEN condicion = ? AND tipo = ? THEN cantidad ELSE 0 END) as robo_extravio',
                    [
                        CondicionDevolucion::Danado->value, TipoMovimiento::Incidencia->value,
                        CondicionDevolucion::Danado->value, TipoMovimiento::Recuperacion->value,
                        CondicionDevolucion::Baja->value, TipoMovimiento::Baja->value,
                        CondicionDevolucion::RoboExtravio->value, TipoMovimiento::Incidencia->value,
                    ],
                )
                ->groupBy('almacen_id', 'talla_id')
                ->get() as $marcado
        ) {
            $almacenId = (int) $marcado->getAttribute('almacen_id');
            $tallaId = $this->tallaId($marcado->getAttribute('talla_id'));
            $fila = &$this->fila($filas, $almacenId, $tallaId);
            $fila['danado'] += max(0, (int) $marcado->getAttribute('danado'));
            $fila['baja'] += (int) $marcado->getAttribute('baja');
            $fila['robo_extravio'] += (int) $marcado->getAttribute('robo_extravio');
            unset($fila);
        }

        return $this->construirResultado(array_values($filas));
    }

    /**
     * @param  array<string, array{almacen_id: int, talla_id: int|null, disponible: int, asignado: int, danado: int, baja: int, robo_extravio: int}>  $filas
     * @return array{almacen_id: int, talla_id: int|null, disponible: int, asignado: int, danado: int, baja: int, robo_extravio: int}
     */
    private function &fila(array &$filas, int $almacenId, ?int $tallaId): array
    {
        // Clave interna de fusión "almacen_id|talla_id" (talla_id vacío, no
        // 0 — el 0 nunca es un valor real de talla en este sistema). Es una
        // clave de array PHP en memoria, nunca se persiste ni se expone tal
        // cual al frontend.
        $clave = "{$almacenId}|{$tallaId}";

        if (! isset($filas[$clave])) {
            $filas[$clave] = [
                'almacen_id' => $almacenId,
                'talla_id' => $tallaId,
                'disponible' => 0,
                'asignado' => 0,
                'danado' => 0,
                'baja' => 0,
                'robo_extravio' => 0,
            ];
        }

        return $filas[$clave];
    }

    private function tallaId(mixed $valor): ?int
    {
        return $valor === null ? null : (int) $valor;
    }

    /**
     * @param  list<array{almacen_id: int, talla_id: int|null, disponible: int, asignado: int, danado: int, baja: int, robo_extravio: int}>  $filas
     * @return array{
     *     resumen: array{disponible: int, asignado: int, danado: int, baja: int, robo_extravio: int},
     *     desglose: list<array{
     *         almacen_id: int,
     *         almacen: string,
     *         variantes: list<array{
     *             talla_id: int|null,
     *             talla: string|null,
     *             disponible: int,
     *             asignado: int,
     *             danado: int,
     *             baja: int,
     *             robo_extravio: int
     *         }>
     *     }>
     * }
     */
    private function construirResultado(array $filas): array
    {
        $almacenIds = [];
        $tallaIds = [];
        foreach ($filas as $f) {
            $almacenIds[$f['almacen_id']] = true;
            if ($f['talla_id'] !== null) {
                $tallaIds[$f['talla_id']] = true;
            }
        }

        // Nombres de almacén/talla en 2 consultas acotadas (nunca una por
        // fila): sólo los IDs realmente involucrados.
        $almacenesPorId = Almacen::query()->whereIn('id', array_keys($almacenIds))->pluck('nombre', 'id');
        $tallasPorId = $tallaIds === []
            ? collect()
            : Talla::query()->whereIn('id', array_keys($tallaIds))->pluck('valor', 'id');

        $porAlmacenId = [];
        foreach ($filas as $f) {
            $porAlmacenId[$f['almacen_id']][] = $f;
        }

        $desglose = [];
        foreach ($porAlmacenId as $almacenId => $variantesFilas) {
            $variantes = [];
            foreach ($variantesFilas as $v) {
                $variantes[] = [
                    'talla_id' => $v['talla_id'],
                    'talla' => $v['talla_id'] === null ? null : (string) ($tallasPorId[$v['talla_id']] ?? '—'),
                    'disponible' => $v['disponible'],
                    'asignado' => $v['asignado'],
                    'danado' => $v['danado'],
                    'baja' => $v['baja'],
                    'robo_extravio' => $v['robo_extravio'],
                ];
            }
            usort($variantes, fn (array $a, array $b): int => ($a['talla'] ?? '') <=> ($b['talla'] ?? ''));

            $desglose[] = [
                'almacen_id' => $almacenId,
                'almacen' => (string) ($almacenesPorId[$almacenId] ?? '—'),
                'variantes' => $variantes,
            ];
        }
        usort($desglose, fn (array $a, array $b): int => $a['almacen'] <=> $b['almacen']);

        $resumen = ['disponible' => 0, 'asignado' => 0, 'danado' => 0, 'baja' => 0, 'robo_extravio' => 0];
        foreach ($filas as $f) {
            $resumen['disponible'] += $f['disponible'];
            $resumen['asignado'] += $f['asignado'];
            $resumen['danado'] += $f['danado'];
            $resumen['baja'] += $f['baja'];
            $resumen['robo_extravio'] += $f['robo_extravio'];
        }

        return ['resumen' => $resumen, 'desglose' => $desglose];
    }
}
