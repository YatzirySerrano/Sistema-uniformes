<?php

namespace App\Acciones;

use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\Almacen;
use App\Models\SaldoInventario;
use App\Models\Sucursal;
use App\Servicios\ServicioAuditoria;
use Illuminate\Support\Facades\DB;

/**
 * Asistente de migración de existencias legacy: traslada los saldos que aún
 * están asociados a una sucursal al almacén que indique el administrador.
 *
 * Garantías:
 * - Sólo procesa filas realmente pendientes (`almacen_id IS NULL`).
 * - No duplica saldos: si el almacén destino ya tiene saldo para el mismo
 *   activo/talla, suma cantidades y elimina la fila legacy.
 * - Idempotente: repetir la operación no vuelve a mover nada.
 * - Deja un movimiento `migracion_legacy` y una entrada de auditoría.
 */
class MigrarSaldosLegacyAAlmacen
{
    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    /**
     * @return int Número de saldos migrados.
     */
    public function ejecutar(int $empresaId, int $sucursalId, int $almacenId, ?int $realizadoPor): int
    {
        $sucursal = Sucursal::query()->where('empresa_id', $empresaId)
            ->findOr($sucursalId, fn () => throw new ExcepcionDeNegocioSimple('La sucursal no pertenece a esta empresa.'));

        $almacen = Almacen::query()->where('empresa_id', $empresaId)
            ->findOr($almacenId, fn () => throw new ExcepcionDeNegocioSimple('El almacén no pertenece a esta empresa.'));

        if (! $almacen->activo) {
            throw new ExcepcionDeNegocioSimple('El almacén de destino está desactivado.');
        }

        return DB::transaction(function () use ($empresaId, $sucursal, $almacen, $realizadoPor): int {
            $pendientes = SaldoInventario::query()
                ->where('empresa_id', $empresaId)
                ->where('sucursal_id', $sucursal->id)
                ->whereNull('almacen_id')
                ->lockForUpdate()
                ->get();

            if ($pendientes->isEmpty()) {
                return 0;
            }

            $ahora = now();
            $movidos = 0;

            foreach ($pendientes as $legacy) {
                $destino = SaldoInventario::query()
                    ->where('empresa_id', $empresaId)
                    ->where('almacen_id', $almacen->id)
                    ->where('activo_id', $legacy->activo_id)
                    ->where('talla_id', $legacy->talla_id)
                    ->lockForUpdate()
                    ->first();

                $anterior = (int) ($destino->cantidad ?? 0);

                if ($destino !== null) {
                    $destino->update([
                        'cantidad' => $destino->cantidad + $legacy->cantidad,
                        'minimo' => max($destino->minimo, $legacy->minimo),
                    ]);
                    $legacy->delete();
                } else {
                    $legacy->update([
                        'almacen_id' => $almacen->id,
                        'sucursal_id' => null,
                    ]);
                }

                if ((int) $legacy->cantidad !== 0) {
                    $almacen->movimientos()->create([
                        'empresa_id' => $empresaId,
                        'sucursal_id' => $sucursal->id,
                        'activo_id' => $legacy->activo_id,
                        'talla_id' => $legacy->talla_id,
                        'tipo' => 'migracion_legacy',
                        'direccion' => 'entrada',
                        'cantidad' => abs((int) $legacy->cantidad),
                        'existencia_anterior' => $anterior,
                        'existencia_resultante' => $anterior + (int) $legacy->cantidad,
                        'referencia_tipo' => 'migracion_legacy',
                        'referencia_id' => $sucursal->id,
                        'motivo' => 'Migración manual de existencias (sucursal → almacén)',
                        'realizado_por' => $realizadoPor,
                        'ocurrido_en' => $ahora,
                    ]);
                }

                $movidos++;
            }

            $this->auditoria->registrar('inventario', 'migracion_legacy', [
                'sucursal_id' => $sucursal->id,
                'descripcion' => "Migración de {$movidos} saldo(s) de la sucursal «{$sucursal->nombre}» al almacén «{$almacen->nombre}».",
                'valores_nuevos' => ['sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id, 'saldos' => $movidos],
            ]);

            return $movidos;
        });
    }
}
