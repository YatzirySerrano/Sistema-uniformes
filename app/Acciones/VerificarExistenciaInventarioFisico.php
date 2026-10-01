<?php

namespace App\Acciones;

use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Excepciones\VerificacionInventarioFisicoConcurrenteException;
use App\Models\InventarioFisico;
use App\Models\InventarioFisicoExistencia;
use App\Models\User;
use App\Servicios\ServicioAuditoria;
use App\Soporte\FechaHora;
use Illuminate\Support\Facades\DB;

/**
 * Registra la cantidad CONTADA de un renglón de artículos por cantidad
 * (almacén + activo + variante) dentro de una ronda EN PROCESO. El inventario
 * físico sólo COMPARA: esto nunca ajusta `saldos_inventario`.
 *
 * Varios encargados trabajan la MISMA ronda a la vez. Concurrencia optimista:
 * el cliente envía `$verificadaEnVista`, la `verificada_en` que tenía en
 * pantalla (null = lo veía pendiente). Bajo lock:
 *
 *  - Si el renglón ya fue verificado y el cliente no lo sabía (o vio otra
 *    versión) → `VerificacionInventarioFisicoConcurrenteException` con la
 *    fila real: nunca "last write wins" silencioso.
 *  - Si coincide → se registra; si reemplaza el conteo de OTRA persona, la
 *    corrección queda en la bitácora (quién, antes/después).
 *
 * Cierre atómico: la ronda `Finalizada` es inmutable (orden de locks fijo
 * Ronda → renglón, igual que `FinalizarRondaInventarioFisico`).
 */
class VerificarExistenciaInventarioFisico
{
    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    public function ejecutar(
        InventarioFisico $ronda,
        InventarioFisicoExistencia $existencia,
        int $cantidadContada,
        User $usuario,
        ?string $verificadaEnVista = null,
    ): InventarioFisicoExistencia {
        if ($cantidadContada < 0) {
            throw new ExcepcionDeNegocioSimple('La cantidad contada no puede ser negativa.');
        }

        return DB::transaction(function () use ($ronda, $existencia, $cantidadContada, $usuario, $verificadaEnVista): InventarioFisicoExistencia {
            $bloqueada = InventarioFisico::query()->whereKey($ronda->id)->lockForUpdate()->firstOrFail();

            if (! $bloqueada->estaEnProceso()) {
                throw new ExcepcionDeNegocioSimple('Esta ronda ya fue finalizada; no admite cambios.');
            }

            $fila = InventarioFisicoExistencia::query()
                ->where('inventario_fisico_id', $bloqueada->id)
                ->whereKey($existencia->id)
                ->lockForUpdate()
                ->firstOrFail();

            $versionActual = $fila->verificada_en?->toIso8601String();

            if ($fila->verificada_en !== null && $versionActual !== $verificadaEnVista) {
                $fila->loadMissing('verificadaPor:id,name');

                throw new VerificacionInventarioFisicoConcurrenteException(sprintf(
                    'Este renglón ya fue contado por %s el %s (%d). Se muestra el conteo actual; si necesitas corregirlo, vuelve a guardarlo.',
                    $fila->verificadaPor->name ?? 'otro usuario',
                    FechaHora::local($fila->verificada_en),
                    (int) $fila->cantidad_contada,
                ), $fila);
            }

            $anterior = ['cantidad_contada' => $fila->cantidad_contada, 'verificada_por' => $fila->verificada_por];

            $fila->update([
                'cantidad_contada' => $cantidadContada,
                'verificada_por' => $usuario->id,
                'verificada_en' => now(),
            ]);

            // Corrección de un conteo ya registrado: queda trazado.
            if ($anterior['cantidad_contada'] !== null) {
                $fila->loadMissing(['activo:id,nombre', 'talla:id,valor', 'almacen:id,nombre']);
                $this->auditoria->registrar('inventario_fisico', 'existencia_corregir_conteo', [
                    'empresa_id' => $bloqueada->empresa_id,
                    'tipo_entidad' => InventarioFisicoExistencia::class,
                    'entidad_id' => $fila->id,
                    'descripcion' => sprintf(
                        'Conteo corregido en la ronda %s: %s%s en %s.',
                        $bloqueada->folio,
                        $fila->activo->nombre ?? 'activo',
                        $fila->talla !== null ? ' talla '.$fila->talla->valor : '',
                        $fila->almacen->nombre ?? 'almacén',
                    ),
                    'valores_anteriores' => ['cantidad_contada' => $anterior['cantidad_contada']],
                    'valores_nuevos' => ['cantidad_contada' => $cantidadContada],
                ]);
            }

            return $fila;
        });
    }
}
