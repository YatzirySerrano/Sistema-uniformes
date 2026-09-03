<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Migración de existencias legacy (por sucursal) → inventario por almacén.
     *
     * Estrategia SEGURA, sin adivinar:
     * - Caso A: la sucursal es abastecida por EXACTAMENTE un almacén activo →
     *   el saldo se traslada a ese almacén (misma fila; `sucursal_id` pasa a
     *   NULL). Si ya existía saldo en ese almacén para el mismo activo/talla, se
     *   suman las cantidades y se elimina la fila legacy. Se registra un
     *   movimiento `migracion_legacy` por trazabilidad.
     * - Caso B (varios almacenes posibles) y Caso C (ningún almacén): NO se
     *   toca. Queda pendiente y se resuelve con el "Asistente de migración de
     *   existencias".
     *
     * Idempotente: sólo procesa filas con `almacen_id IS NULL` cuya sucursal
     * tenga un único abastecedor. No duplica saldos.
     */
    public function up(): void
    {
        $ahora = now();

        $pendientes = DB::table('saldos_inventario')
            ->whereNull('almacen_id')
            ->whereNotNull('sucursal_id')
            ->get();

        $sucursalesMigradas = [];

        foreach ($pendientes as $saldo) {
            $candidatos = DB::table('almacen_sucursal as xs')
                ->join('almacenes as al', 'al.id', '=', 'xs.almacen_id')
                ->where('xs.sucursal_id', $saldo->sucursal_id)
                ->where('al.empresa_id', $saldo->empresa_id)
                ->where('al.activo', true)
                ->pluck('al.id');

            if ($candidatos->count() !== 1) {
                continue; // Caso B / C: lo resuelve el asistente.
            }

            $almacenId = (int) $candidatos->first();

            $destino = DB::table('saldos_inventario')
                ->where('empresa_id', $saldo->empresa_id)
                ->where('almacen_id', $almacenId)
                ->where('activo_id', $saldo->activo_id)
                ->where('talla_id', $saldo->talla_id)
                ->first();

            $anterior = $destino->cantidad ?? 0;

            if ($destino !== null) {
                DB::table('saldos_inventario')->where('id', $destino->id)->update([
                    'cantidad' => $destino->cantidad + $saldo->cantidad,
                    'minimo' => max($destino->minimo, $saldo->minimo),
                    'updated_at' => $ahora,
                ]);
                DB::table('saldos_inventario')->where('id', $saldo->id)->delete();
            } else {
                DB::table('saldos_inventario')->where('id', $saldo->id)->update([
                    'almacen_id' => $almacenId,
                    'sucursal_id' => null,
                    'updated_at' => $ahora,
                ]);
            }

            if ($saldo->cantidad != 0) {
                DB::table('movimientos_inventario')->insert([
                    'empresa_id' => $saldo->empresa_id,
                    'almacen_id' => $almacenId,
                    'sucursal_id' => $saldo->sucursal_id,
                    'activo_id' => $saldo->activo_id,
                    'talla_id' => $saldo->talla_id,
                    'tipo' => 'migracion_legacy',
                    'direccion' => 'entrada',
                    'cantidad' => abs($saldo->cantidad),
                    'existencia_anterior' => $anterior,
                    'existencia_resultante' => $anterior + $saldo->cantidad,
                    'referencia_tipo' => 'migracion_legacy',
                    'referencia_id' => $saldo->sucursal_id,
                    'motivo' => 'Migración de inventario por sucursal a almacén',
                    'realizado_por' => null,
                    'ocurrido_en' => $ahora,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);
            }

            $sucursalesMigradas[$saldo->sucursal_id] = $almacenId;
        }

        // Trazabilidad de procedencia en el historial: sólo cuando el
        // abastecedor es inequívoco. No se altera `sucursal_id` en movimientos.
        DB::table('movimientos_inventario')
            ->whereNull('almacen_id')
            ->whereNotNull('sucursal_id')
            ->orderBy('id')
            ->chunkById(500, function ($movimientos): void {
                foreach ($movimientos as $mov) {
                    $candidatos = DB::table('almacen_sucursal as xs')
                        ->join('almacenes as al', 'al.id', '=', 'xs.almacen_id')
                        ->where('xs.sucursal_id', $mov->sucursal_id)
                        ->where('al.empresa_id', $mov->empresa_id)
                        ->where('al.activo', true)
                        ->pluck('al.id');

                    if ($candidatos->count() === 1) {
                        DB::table('movimientos_inventario')->where('id', $mov->id)
                            ->update(['almacen_id' => (int) $candidatos->first()]);
                    }
                }
            });

        foreach ($sucursalesMigradas as $sucursalId => $almacenId) {
            DB::table('bitacora_auditoria')->insert([
                'usuario_id' => null,
                'nombre_usuario_snapshot' => 'Sistema (migración)',
                'empresa_id' => DB::table('sucursales')->where('id', $sucursalId)->value('empresa_id'),
                'sucursal_id' => $sucursalId,
                'modulo' => 'inventario',
                'accion' => 'migracion_legacy',
                'descripcion' => "Existencias de la sucursal #{$sucursalId} trasladadas automáticamente al almacén #{$almacenId} (abastecedor único).",
                'created_at' => $ahora,
            ]);
        }
    }

    public function down(): void
    {
        // No reversible con seguridad: el traslado de existencias no se deshace
        // automáticamente para no corromper saldos. Los movimientos
        // `migracion_legacy` quedan como historia.
    }
};
