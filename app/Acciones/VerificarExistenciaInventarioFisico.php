<?php

namespace App\Acciones;

use App\Enums\FinalidadCustodia;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Excepciones\VerificacionInventarioFisicoConcurrenteException;
use App\Models\InventarioFisico;
use App\Models\InventarioFisicoExistencia;
use App\Models\User;
use App\Servicios\ServicioAuditoria;
use App\Soporte\FechaHora;
use Illuminate\Support\Facades\DB;

/**
 * Resuelve un renglón de artículos por cantidad (almacén + activo + variante,
 * o custodio + activo + variante + finalidad) dentro de una ronda EN PROCESO:
 *
 *  - `ejecutar()`: registra la cantidad CONTADA (Coincide / Faltante /
 *    Sobrante). Si el renglón estaba como "No fue posible verificar", el
 *    conteo lo sustituye (se comprobó finalmente).
 *  - `marcarNoVerificable()`: "No fue posible verificar" con motivo opcional.
 *    `cantidad_contada` queda NULL — nunca es un 0 — y no hay diferencia;
 *    `verificada_por/en` registran quién y cuándo. El motivo se guarda en la
 *    bitácora (`existencia_no_verificable`) en la MISMA transacción: es la
 *    fuente del motivo (`ServicioResumenInventarioFisico::motivosNoVerificables()`).
 *  - `reabrir()`: devuelve un renglón no verificable a Pendiente.
 *
 * El inventario físico sólo COMPARA: nada de esto ajusta `saldos_inventario`
 * ni cambia custodio o finalidad.
 *
 * Varios encargados trabajan la MISMA ronda a la vez. Concurrencia optimista
 * ÚNICA para las tres operaciones: el cliente envía `$verificadaEnVista`, la
 * `verificada_en` que tenía en pantalla (null = lo veía pendiente). Bajo lock,
 * si el renglón ya fue resuelto (contado o no verificable) y el cliente no lo
 * sabía (o vio otra versión) → `VerificacionInventarioFisicoConcurrenteException`
 * con la fila real: nunca "last write wins" silencioso. Cambiar algo ya
 * resuelto queda en la bitácora.
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

        return $this->bajoCandado($ronda, $existencia, $verificadaEnVista, function (InventarioFisico $bloqueada, InventarioFisicoExistencia $fila) use ($cantidadContada, $usuario): InventarioFisicoExistencia {
            $yaResuelta = $fila->resuelta();
            $anterior = $this->estado($fila);

            $fila->update([
                'cantidad_contada' => $cantidadContada,
                'verificada_por' => $usuario->id,
                'verificada_en' => now(),
            ]);

            // Corrección de un conteo (o de un "no verificable") ya registrado: queda trazado.
            if ($yaResuelta) {
                $this->auditar($bloqueada, $fila, 'existencia_corregir_conteo', 'Conteo corregido', $anterior, $this->estado($fila));
            }

            return $fila;
        });
    }

    public function marcarNoVerificable(
        InventarioFisico $ronda,
        InventarioFisicoExistencia $existencia,
        ?string $motivo,
        User $usuario,
        ?string $verificadaEnVista = null,
    ): InventarioFisicoExistencia {
        $motivo = $motivo !== null && trim($motivo) !== '' ? trim($motivo) : null;

        return $this->bajoCandado($ronda, $existencia, $verificadaEnVista, function (InventarioFisico $bloqueada, InventarioFisicoExistencia $fila) use ($motivo, $usuario): InventarioFisicoExistencia {
            $anterior = $this->estado($fila);

            $fila->update([
                'cantidad_contada' => null,
                'verificada_por' => $usuario->id,
                'verificada_en' => now(),
            ]);

            $this->auditar(
                $bloqueada,
                $fila,
                'existencia_no_verificable',
                'Marcado como «No fue posible verificar»',
                $anterior,
                ['no_verificable' => true, 'motivo_no_verificable' => $motivo],
                $motivo,
            );

            return $fila;
        });
    }

    public function reabrir(
        InventarioFisico $ronda,
        InventarioFisicoExistencia $existencia,
        User $usuario,
        ?string $verificadaEnVista = null,
    ): InventarioFisicoExistencia {
        return $this->bajoCandado($ronda, $existencia, $verificadaEnVista, function (InventarioFisico $bloqueada, InventarioFisicoExistencia $fila): InventarioFisicoExistencia {
            if (! $fila->resuelta()) {
                // Otra persona ya lo reabrió: mismo resultado, nada que hacer.
                return $fila;
            }

            if (! $fila->esNoVerificable()) {
                throw new ExcepcionDeNegocioSimple('Sólo se puede reabrir un renglón marcado como «No fue posible verificar». Para corregir un conteo, captura la cantidad de nuevo.');
            }

            $anterior = $this->estado($fila);

            $fila->update([
                'cantidad_contada' => null,
                'verificada_por' => null,
                'verificada_en' => null,
            ]);

            $this->auditar($bloqueada, $fila, 'existencia_reabrir', 'Reabierto como pendiente', $anterior, ['cantidad_contada' => null]);

            return $fila;
        });
    }

    /**
     * Ronda → renglón bajo `lockForUpdate()`, ronda abierta y versión vista
     * vigente; después aplica `$cambio` en la misma transacción.
     *
     * @param  callable(InventarioFisico, InventarioFisicoExistencia): InventarioFisicoExistencia  $cambio
     */
    private function bajoCandado(InventarioFisico $ronda, InventarioFisicoExistencia $existencia, ?string $verificadaEnVista, callable $cambio): InventarioFisicoExistencia
    {
        return DB::transaction(function () use ($ronda, $existencia, $verificadaEnVista, $cambio): InventarioFisicoExistencia {
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

                throw new VerificacionInventarioFisicoConcurrenteException($fila->esNoVerificable()
                    ? sprintf(
                        '%s ya marcó este renglón como «No fue posible verificar» el %s. Se muestra el estado actual; si necesitas cambiarlo, vuelve a intentarlo.',
                        $fila->verificadaPor->name ?? 'Otro usuario',
                        FechaHora::local($fila->verificada_en),
                    )
                    : sprintf(
                        'Este renglón ya fue contado por %s el %s (%d). Se muestra el conteo actual; si necesitas corregirlo, vuelve a guardarlo.',
                        $fila->verificadaPor->name ?? 'otro usuario',
                        FechaHora::local($fila->verificada_en),
                        (int) $fila->cantidad_contada,
                    ), $fila);
            }

            return $cambio($bloqueada, $fila);
        });
    }

    /**
     * Estado legible para la bitácora: el conteo sigue registrándose como
     * `['cantidad_contada' => N]`; la resolución, como `no_verificable`.
     *
     * @return array<string, int|bool|null>
     */
    private function estado(InventarioFisicoExistencia $fila): array
    {
        return $fila->esNoVerificable()
            ? ['no_verificable' => true]
            : ['cantidad_contada' => $fila->cantidad_contada];
    }

    /**
     * @param  array<string, int|bool|string|null>  $anterior
     * @param  array<string, int|bool|string|null>  $nuevo
     */
    private function auditar(InventarioFisico $ronda, InventarioFisicoExistencia $fila, string $accion, string $que, array $anterior, array $nuevo, ?string $motivo = null): void
    {
        $fila->loadMissing(['activo:id,nombre', 'talla:id,valor', 'almacen:id,nombre', 'colaborador:id,nombre_completo']);

        $this->auditoria->registrar('inventario_fisico', $accion, [
            'empresa_id' => $ronda->empresa_id,
            'tipo_entidad' => InventarioFisicoExistencia::class,
            'entidad_id' => $fila->id,
            'descripcion' => sprintf(
                '%s en la ronda %s: %s%s %s.',
                $que,
                $ronda->folio,
                $fila->activo->nombre ?? 'activo',
                $fila->talla !== null ? ' talla '.$fila->talla->valor : '',
                $fila->esCustodia()
                    ? 'bajo custodia de '.($fila->colaborador->nombre_completo ?? 'colaborador').' ('.FinalidadCustodia::etiquetaDe($fila->finalidad).')'
                    : 'en '.($fila->almacen->nombre ?? 'almacén'),
            ),
            'valores_anteriores' => $anterior,
            'valores_nuevos' => $nuevo,
            'motivo' => $motivo,
        ]);
    }
}
