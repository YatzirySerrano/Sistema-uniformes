<?php

namespace App\Acciones;

use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Excepciones\VerificacionInventarioFisicoConcurrenteException;
use App\Models\InventarioFisico;
use App\Models\InventarioFisicoUnidad;
use App\Models\User;
use App\Soporte\FechaHora;
use Illuminate\Support\Facades\DB;

/**
 * Marca una unidad identificada ESPERADA como presente SIN escanear su QR: el
 * escaneo es sólo un atajo rápido de esta misma comprobación (una unidad pudo
 * perder su etiqueta física, o estar asignada lejos y confirmarse por otro
 * medio). Escribe `escaneado_en` / `escaneado_por` igual que un escaneo y
 * NUNCA toca `UnidadActivo` (estado, condición, custodio, almacén).
 *
 * Varios encargados trabajan la misma ronda: GANA LA PRIMERA verificación.
 * Si otra persona ya la marcó, no se sobrescribe su autoría: se lanza
 * `VerificacionInventarioFisicoConcurrenteException` con quién y cuándo, y la
 * fila real. Repetir la marca propia es idempotente.
 *
 * Cierre atómico: `lockForUpdate` de la ronda → estado `EnProceso` →
 * `lockForUpdate` del renglón de ESA ronda.
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

                return $fila;
            }

            if ($fila->escaneado_por !== null && $fila->escaneado_por !== $usuario->id) {
                $fila->loadMissing('escaneadoPor:id,name');

                throw new VerificacionInventarioFisicoConcurrenteException(sprintf(
                    'Esta unidad ya fue verificada por %s el %s.',
                    $fila->escaneadoPor->name ?? 'otro usuario',
                    FechaHora::local($fila->escaneado_en),
                ), $fila);
            }

            return $fila;
        });
    }
}
