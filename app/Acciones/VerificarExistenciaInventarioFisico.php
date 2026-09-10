<?php

namespace App\Acciones;

use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\InventarioFisico;
use App\Models\InventarioFisicoExistencia;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Registra la cantidad CONTADA de un renglón de artículos por cantidad dentro
 * de una ronda EN PROCESO. El inventario físico sólo COMPARA: esto nunca ajusta
 * `saldos_inventario`.
 *
 * Cierre atómico: la ronda `Finalizada` es inmutable. La validación del estado
 * ocurre DENTRO de la transacción, tras `lockForUpdate` de la ronda, y los
 * locks se toman siempre en el mismo orden (Ronda → renglón) para no chocar con
 * `FinalizarRondaInventarioFisico`.
 */
class VerificarExistenciaInventarioFisico
{
    public function ejecutar(
        InventarioFisico $ronda,
        InventarioFisicoExistencia $existencia,
        int $cantidadContada,
        User $usuario,
    ): InventarioFisicoExistencia {
        if ($cantidadContada < 0) {
            throw new ExcepcionDeNegocioSimple('La cantidad contada no puede ser negativa.');
        }

        return DB::transaction(function () use ($ronda, $existencia, $cantidadContada, $usuario): InventarioFisicoExistencia {
            $bloqueada = InventarioFisico::query()->whereKey($ronda->id)->lockForUpdate()->firstOrFail();

            if (! $bloqueada->estaEnProceso()) {
                throw new ExcepcionDeNegocioSimple('Esta ronda ya fue finalizada; no admite cambios.');
            }

            $fila = InventarioFisicoExistencia::query()
                ->where('inventario_fisico_id', $bloqueada->id)
                ->whereKey($existencia->id)
                ->lockForUpdate()
                ->firstOrFail();

            $fila->update([
                'cantidad_contada' => $cantidadContada,
                'verificada_por' => $usuario->id,
                'verificada_en' => now(),
            ]);

            return $fila;
        });
    }
}
