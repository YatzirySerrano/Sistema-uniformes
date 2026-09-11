<?php

namespace App\Acciones;

use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\InventarioFisico;
use App\Models\InventarioFisicoUnidad;
use Illuminate\Support\Facades\DB;

/**
 * Revierte la marca de "presente" de una unidad identificada ESPERADA antes de
 * que la ronda se finalice: limpia `escaneado_en` / `escaneado_por` para que la
 * unidad vuelva a contar como faltante. Es el reverso exacto de
 * `MarcarUnidadPresente` (el escaneo QR y la marca manual producen el mismo
 * resultado; deshacer los cubre a ambos).
 *
 * Cierre atómico: mismo patrón que `MarcarUnidadPresente` /
 * `VerificarExistenciaInventarioFisico` — `lockForUpdate` de la ronda → estado
 * `EnProceso` → `lockForUpdate` del renglón de ESA ronda. Idempotente si la
 * unidad ya no estaba marcada. Nunca toca `UnidadActivo`.
 */
class DesmarcarUnidadPresente
{
    public function ejecutar(
        InventarioFisico $ronda,
        InventarioFisicoUnidad $renglon,
    ): InventarioFisicoUnidad {
        return DB::transaction(function () use ($ronda, $renglon): InventarioFisicoUnidad {
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

            if ($fila->escaneado_en !== null) {
                $fila->update(['escaneado_en' => null, 'escaneado_por' => null]);
            }

            return $fila;
        });
    }
}
