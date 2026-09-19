<?php

namespace App\Acciones;

use App\Enums\EstadoUnidadActivo;
use App\Enums\TipoReserva;
use App\Models\DetalleEntrega;
use App\Servicios\ServicioCustodiaColaborador;
use App\Servicios\ServicioReservas;
use Illuminate\Support\Facades\DB;

/**
 * Recalcula, de forma ATÓMICA, el apartado temporal de UN borrador de
 * Devolución completo. A diferencia de `ReservarInventarioEntrega`, NUNCA
 * aparta stock de almacén — aparta el DERECHO a devolver una custodia
 * pendiente concreta (`DetalleEntrega`), para que dos usuarios no intenten
 * devolver lo mismo a la vez.
 *
 * Reutiliza `ServicioCustodiaColaborador::pendienteDeDetalle()` (fuente
 * ÚNICA del pendiente real, confirmado) y le resta las reservas de
 * DEVOLUCIÓN activas de otros usuarios — nunca reimplementa ese cálculo.
 */
class ReservarCustodiaDevolucion
{
    public function __construct(
        private readonly ServicioReservas $reservas,
        private readonly ServicioCustodiaColaborador $custodia,
    ) {}

    /**
     * @param  array<int, array{detalle_entrega_id: int|string|null, cantidad: int|string|null}>  $itemsCantidad  Reglas laxas (ver controller): un renglón a medio llenar puede traer `''`/`null`.
     * @param  array<int, array{detalle_entrega_id: int|string|null}>  $itemsUnidad
     * @return array{
     *     token: string, expira_en: string, ok: bool,
     *     lineas_cantidad: array<int, array{detalle_entrega_id: int, activo_nombre: ?string, talla_valor: ?string, pendiente_real: int, disponible_efectivo: int, solicitado: int, suficiente: bool}>,
     *     lineas_unidad: array<int, array{detalle_entrega_id: int, unidad_activo_id: ?int, ok: bool, motivo: ?string}>,
     * }
     */
    public function ejecutar(
        string $token,
        int $userId,
        int $empresaId,
        ?int $colaboradorId,
        int $entregaId,
        array $itemsCantidad,
        array $itemsUnidad,
    ): array {
        return DB::transaction(function () use ($token, $userId, $empresaId, $colaboradorId, $entregaId, $itemsCantidad, $itemsUnidad): array {
            $reserva = $this->reservas->obtenerOCrearCabecera($token, TipoReserva::Devolucion, $userId, $empresaId, null, $colaboradorId, $entregaId);

            /** @var array<int, int> $solicitadoPorDetalle */
            $solicitadoPorDetalle = [];
            foreach ($itemsCantidad as $fila) {
                if (($fila['detalle_entrega_id'] ?? '') === '') {
                    continue;
                }
                $id = (int) $fila['detalle_entrega_id'];
                $cantidad = (int) ($fila['cantidad'] ?? 0);
                if ($cantidad <= 0) {
                    continue;
                }
                $solicitadoPorDetalle[$id] = ($solicitadoPorDetalle[$id] ?? 0) + $cantidad;
            }

            $ids = array_keys($solicitadoPorDetalle);
            sort($ids);

            $lineasCantidad = [];
            foreach ($ids as $id) {
                $detalle = DetalleEntrega::query()->whereKey($id)->lockForUpdate()->first();
                if ($detalle === null) {
                    continue;
                }

                $pendienteReal = $this->custodia->pendienteDeDetalle($detalle);
                $apartadoOtros = $this->reservas->custodiaApartadaDeOtros($id, $token);
                $disponibleEfectivo = max(0, $pendienteReal - $apartadoOtros);
                $solicitado = $solicitadoPorDetalle[$id];
                $aReservar = min($solicitado, $disponibleEfectivo);

                if ($aReservar > 0) {
                    $reserva->renglones()->create(['detalle_entrega_id' => $id, 'cantidad' => $aReservar]);
                }

                $lineasCantidad[] = [
                    'detalle_entrega_id' => $id,
                    'activo_nombre' => $detalle->activo_nombre_snapshot,
                    'talla_valor' => $detalle->talla_valor_snapshot,
                    'pendiente_real' => $pendienteReal,
                    'disponible_efectivo' => $disponibleEfectivo,
                    'solicitado' => $solicitado,
                    'suficiente' => $solicitado <= $disponibleEfectivo,
                ];
            }

            $lineasUnidad = [];
            $idsUnidadVistos = [];
            foreach ($itemsUnidad as $fila) {
                if (($fila['detalle_entrega_id'] ?? '') === '') {
                    continue;
                }
                $detalleId = (int) $fila['detalle_entrega_id'];
                if (in_array($detalleId, $idsUnidadVistos, true)) {
                    continue;
                }
                $idsUnidadVistos[] = $detalleId;

                $detalle = DetalleEntrega::query()->with('unidadActivo')->whereKey($detalleId)->first();
                $unidad = $detalle?->unidadActivo;

                if ($unidad === null) {
                    $lineasUnidad[] = ['detalle_entrega_id' => $detalleId, 'unidad_activo_id' => null, 'ok' => false, 'motivo' => 'Esa unidad ya no corresponde a esta entrega.'];

                    continue;
                }

                $unidadBloqueada = $unidad->newQuery()->whereKey($unidad->id)->lockForUpdate()->first();

                if ($unidadBloqueada === null || $unidadBloqueada->estado !== EstadoUnidadActivo::Asignada) {
                    $lineasUnidad[] = ['detalle_entrega_id' => $detalleId, 'unidad_activo_id' => $unidad->id, 'ok' => false, 'motivo' => 'Esta unidad ya no está asignada; no se puede devolver.'];

                    continue;
                }

                if ($this->reservas->unidadApartadaPorOtro($unidad->id, $token)) {
                    $lineasUnidad[] = ['detalle_entrega_id' => $detalleId, 'unidad_activo_id' => $unidad->id, 'ok' => false, 'motivo' => 'Esta unidad acaba de ser apartada por otra devolución.'];

                    continue;
                }

                $reserva->renglones()->create(['unidad_activo_id' => $unidad->id, 'detalle_entrega_id' => $detalleId]);
                $lineasUnidad[] = ['detalle_entrega_id' => $detalleId, 'unidad_activo_id' => $unidad->id, 'ok' => true, 'motivo' => null];
            }

            $ok = ! in_array(false, array_column($lineasCantidad, 'suficiente'), true)
                && ! in_array(false, array_column($lineasUnidad, 'ok'), true);

            return [
                'token' => $reserva->token,
                'expira_en' => $reserva->expira_en->toIso8601String(),
                'ok' => $ok,
                'lineas_cantidad' => $lineasCantidad,
                'lineas_unidad' => $lineasUnidad,
            ];
        });
    }
}
