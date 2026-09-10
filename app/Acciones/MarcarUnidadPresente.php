<?php

namespace App\Acciones;

use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\InventarioFisico;
use App\Models\InventarioFisicoUnidad;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Marca una unidad identificada ESPERADA como presente SIN escanear su QR: el
 * escaneo es sólo un atajo rápido de esta misma comprobación (una unidad pudo
 * perder su etiqueta física y seguir estando). Escribe `escaneado_en` /
 * `escaneado_por` igual que un escaneo.
 *
 * Cierre atómico: mismo patrón que `VerificarExistenciaInventarioFisico` —
 * `lockForUpdate` de la ronda → estado `EnProceso` → `lockForUpdate` del
 * renglón de ESA ronda. Idempotente si ya estaba marcada.
 */
class MarcarUnidadPresente
{
    public function ejecutar(
        InventarioFisico $ronda,
        InventarioFisicoUnidad $renglon,
        User $usuario,
    ): InventarioFisicoUnidad {
        return DB::transaction(function () use ($ronda, $renglon, $usuario): InventarioFisicoUnidad {
            $bloqueada = InventarioFisico::query()->whereKey($ronda->id)->lockForUpdate()->firstOrFail();

            if (! $bloqueada->estaEnProceso()) {
                throw new ExcepcionDeNegocioSimple('Esta ronda ya fue finalizada; no admite cambios.');
            }

            $fila = InventarioFisicoUnidad::query()
                ->where('inventario_fisico_id', $bloqueada->id)
                ->where('esperada', true)
                ->whereKey($renglon->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($fila->escaneado_en === null) {
                $fila->update(['escaneado_en' => now(), 'escaneado_por' => $usuario->id]);
            }

            return $fila;
        });
    }
}
