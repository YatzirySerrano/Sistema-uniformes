<?php

namespace App\Acciones;

use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\CambioServicioColaborador;
use App\Models\Colaborador;
use App\Models\Servicio;
use App\Servicios\ServicioCambioServicio;
use Illuminate\Support\Facades\DB;

/**
 * Completa el cambio de servicio SÓLO si la revisión de custodia está
 * resuelta en la realidad (no en lo que el formulario cargó al abrirse):
 * todo decidido, devoluciones confirmadas, redistribuciones firmadas y la
 * custodia actual coincide con lo revisado. Se evalúa DENTRO de la
 * transacción con el colaborador bloqueado — el mismo candado que toman las
 * entregas y redistribuciones —, así un cambio concurrente de custodia se
 * detecta y el cambio se rechaza en vez de aplicarse sobre un snapshot viejo.
 */
class CompletarCambioServicio
{
    public function __construct(
        private readonly ServicioCambioServicio $cambios,
        private readonly CambiarServicioColaborador $cambiarServicio,
    ) {}

    public function ejecutar(CambioServicioColaborador $cambio, int $usuarioId): CambioServicioColaborador
    {
        return DB::transaction(function () use ($cambio, $usuarioId): CambioServicioColaborador {
            /** @var Colaborador $colaborador */
            $colaborador = Colaborador::query()->whereKey($cambio->colaborador_id)->lockForUpdate()->firstOrFail();

            /** @var CambioServicioColaborador $cambio */
            $cambio = CambioServicioColaborador::query()->whereKey($cambio->getKey())->lockForUpdate()->firstOrFail();

            if (! $cambio->estaPendiente()) {
                throw new ExcepcionDeNegocioSimple('Esta revisión de cambio de servicio ya fue completada o cancelada.');
            }

            if ($colaborador->empresa_id !== $cambio->empresa_id || $colaborador->servicio_actual_id !== $cambio->servicio_origen_id) {
                throw new ExcepcionDeNegocioSimple('La empresa o el servicio del colaborador cambió después de iniciar esta revisión. Cancélala e inicia una nueva.');
            }

            $this->validarServicioDestino($cambio, $colaborador);

            $evaluacion = $this->cambios->evaluar($cambio);

            if (! $evaluacion['completable']) {
                throw new ExcepcionDeNegocioSimple($evaluacion['nuevos'] !== []
                    ? 'El colaborador recibió bienes nuevos después de iniciar la revisión. Agrégalos a la revisión y decide qué pasa con ellos.'
                    : 'Todavía hay bienes sin resolver (sin decisión, con devolución o redistribución pendiente, o cuya custodia cambió). Revisa el estado de cada uno.');
            }

            $resumen = $this->cambios->resumenDecisiones($cambio, $evaluacion);

            $this->cambiarServicio->aplicar($colaborador, $cambio->servicio_destino_id, $cambio->motivo, $resumen);

            $cambio->update([
                'estado' => CambioServicioColaborador::COMPLETADO,
                'completado_por' => $usuarioId,
                'completado_en' => now(),
            ]);

            return $cambio;
        });
    }

    /**
     * El servicio de destino se validó al iniciar, pero pudo desactivarse
     * mientras la revisión estaba pendiente.
     */
    private function validarServicioDestino(CambioServicioColaborador $cambio, Colaborador $colaborador): void
    {
        if ($cambio->servicio_destino_id === null) {
            return;
        }

        $servicio = Servicio::query()->with('contrato')->find($cambio->servicio_destino_id);

        if ($servicio === null || ! $servicio->activo || ! $servicio->contrato->activo || $servicio->contrato->empresa_id !== $colaborador->empresa_id) {
            throw new ExcepcionDeNegocioSimple('El servicio de destino ya no está activo o no pertenece a la empresa del colaborador. Cancela esta revisión e inicia una nueva.');
        }
    }
}
