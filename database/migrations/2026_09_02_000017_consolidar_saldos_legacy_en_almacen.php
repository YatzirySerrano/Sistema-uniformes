<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Cierra definitivamente la transición "inventario por sucursal → por
     * almacén". `saldos_inventario` es la tabla de ESTADO ACTUAL y no puede
     * conservar filas sin almacén.
     *
     * Para cada saldo con `almacen_id IS NULL`:
     * - Si la empresa del saldo tiene EXACTAMENTE un almacén activo (vía
     *   `almacen_empresa`) → se traslada a ese almacén. Si ya existe saldo en
     *   ese almacén para el mismo activo/talla, se suman las cantidades y se
     *   elimina la fila legacy. Se deja un movimiento `migracion_legacy`.
     * - Si no se puede resolver (0 o >1 almacenes) → la fila legacy se elimina.
     *   El historial completo permanece en `movimientos_inventario`
     *   (append-only). Se registra un resumen en la bitácora por empresa.
     *
     * `movimientos_inventario` sólo recibe `almacen_id` cuando el abastecedor
     * es inequívoco; el resto conserva su `sucursal_id` como procedencia.
     *
     * Idempotente y seguro sobre una BD nueva (sin filas legacy = no-op).
     */
    public function up(): void
    {
        $ahora = now();

        /** @var array<int, int> $almacenUnicoPorEmpresa empresa_id => almacen_id (sólo empresas con abastecedor único) */
        $almacenUnicoPorEmpresa = [];
        $descartadosPorEmpresa = [];

        $legacySaldos = DB::table('saldos_inventario')->whereNull('almacen_id')->orderBy('id')->get();

        foreach ($legacySaldos as $saldo) {
            $almacenId = $this->almacenUnico((int) $saldo->empresa_id, $almacenUnicoPorEmpresa);

            if ($almacenId === null) {
                DB::table('saldos_inventario')->where('id', $saldo->id)->delete();
                $descartadosPorEmpresa[$saldo->empresa_id] = ($descartadosPorEmpresa[$saldo->empresa_id] ?? 0) + 1;

                continue;
            }

            $destino = DB::table('saldos_inventario')
                ->where('empresa_id', $saldo->empresa_id)
                ->where('almacen_id', $almacenId)
                ->where('activo_id', $saldo->activo_id)
                ->where('talla_id', $saldo->talla_id)
                ->first();

            $anterior = (int) ($destino->cantidad ?? 0);

            if ($destino !== null) {
                DB::table('saldos_inventario')->where('id', $destino->id)->update([
                    'cantidad' => $destino->cantidad + $saldo->cantidad,
                    'minimo' => max((int) $destino->minimo, (int) $saldo->minimo),
                    'updated_at' => $ahora,
                ]);
                DB::table('saldos_inventario')->where('id', $saldo->id)->delete();
            } else {
                DB::table('saldos_inventario')->where('id', $saldo->id)->update([
                    'almacen_id' => $almacenId,
                    'updated_at' => $ahora,
                ]);
            }

            if ((int) $saldo->cantidad !== 0) {
                DB::table('movimientos_inventario')->insert([
                    'empresa_id' => $saldo->empresa_id,
                    'almacen_id' => $almacenId,
                    'sucursal_id' => $saldo->sucursal_id,
                    'activo_id' => $saldo->activo_id,
                    'talla_id' => $saldo->talla_id,
                    'tipo' => 'migracion_legacy',
                    'direccion' => 'entrada',
                    'cantidad' => abs((int) $saldo->cantidad),
                    'existencia_anterior' => $anterior,
                    'existencia_resultante' => $anterior + (int) $saldo->cantidad,
                    'referencia_tipo' => 'migracion_legacy',
                    'referencia_id' => $saldo->sucursal_id,
                    'motivo' => 'Consolidación de inventario legacy por sucursal en el almacén abastecedor.',
                    'realizado_por' => null,
                    'ocurrido_en' => $ahora,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);
            }
        }

        // Procedencia en el historial: fija `almacen_id` cuando el abastecedor
        // de la empresa es inequívoco. No se altera `sucursal_id`.
        DB::table('movimientos_inventario')
            ->whereNull('almacen_id')
            ->orderBy('id')
            ->chunkById(500, function ($movimientos) use (&$almacenUnicoPorEmpresa): void {
                foreach ($movimientos as $mov) {
                    $almacenId = $this->almacenUnico((int) $mov->empresa_id, $almacenUnicoPorEmpresa);

                    if ($almacenId !== null) {
                        DB::table('movimientos_inventario')->where('id', $mov->id)->update(['almacen_id' => $almacenId]);
                    }
                }
            });

        foreach ($descartadosPorEmpresa as $empresaId => $conteo) {
            DB::table('bitacora_auditoria')->insert([
                'usuario_id' => null,
                'nombre_usuario_snapshot' => 'Sistema (migración)',
                'empresa_id' => $empresaId,
                'sucursal_id' => null,
                'modulo' => 'inventario',
                'accion' => 'migracion_legacy',
                'descripcion' => "Se descartaron {$conteo} saldo(s) legacy por sucursal sin un almacén abastecedor único. El historial permanece en movimientos_inventario.",
                'created_at' => $ahora,
            ]);
        }
    }

    public function down(): void
    {
        // No reversible con seguridad: la consolidación de existencias no se
        // deshace automáticamente para no corromper saldos. Los movimientos
        // `migracion_legacy` quedan como historia.
    }

    /**
     * @param  array<int, int|null>  $cache
     */
    private function almacenUnico(int $empresaId, array &$cache): ?int
    {
        if (array_key_exists($empresaId, $cache)) {
            return $cache[$empresaId];
        }

        $ids = DB::table('almacen_empresa as ae')
            ->join('almacenes as al', 'al.id', '=', 'ae.almacen_id')
            ->where('ae.empresa_id', $empresaId)
            ->where('al.activo', true)
            ->whereNull('al.deleted_at')
            ->pluck('al.id');

        return $cache[$empresaId] = $ids->count() === 1 ? (int) $ids->first() : null;
    }
};
