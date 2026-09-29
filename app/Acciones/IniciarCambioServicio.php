<?php

namespace App\Acciones;

use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\CambioServicioColaborador;
use App\Models\Colaborador;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioCambioServicio;
use App\Servicios\ServicioCustodiaColaborador;
use Illuminate\Support\Facades\DB;

/**
 * Punto de entrada de un cambio de servicio que debe revisar custodia.
 *
 * - Sin custodia: aplica el cambio directamente (mismo resultado que
 *   `CambiarServicioColaborador`) y devuelve `null`.
 * - Con custodia: abre una revisión (`CambioServicioColaborador`) con un
 *   renglón por cada bien bajo custodia, sin decisiones todavía — el cambio
 *   NO se aplica hasta completar la revisión (`CompletarCambioServicio`).
 *
 * Aplica a cualquier cambio real: A → B, A → sin servicio y sin servicio → A.
 */
class IniciarCambioServicio
{
    public function __construct(
        private readonly ServicioCustodiaColaborador $custodia,
        private readonly ServicioCambioServicio $cambios,
        private readonly CambiarServicioColaborador $cambiarServicio,
        private readonly ServicioAuditoria $auditoria,
    ) {}

    public function ejecutar(Colaborador $colaborador, ?int $servicioDestinoId, ?string $motivo, int $usuarioId): ?CambioServicioColaborador
    {
        return DB::transaction(function () use ($colaborador, $servicioDestinoId, $motivo, $usuarioId): ?CambioServicioColaborador {
            /** @var Colaborador $colaborador */
            $colaborador = Colaborador::query()->whereKey($colaborador->getKey())->lockForUpdate()->firstOrFail();

            if ($colaborador->servicio_actual_id === $servicioDestinoId) {
                throw new ExcepcionDeNegocioSimple('El colaborador ya está en ese servicio.');
            }

            $enCurso = CambioServicioColaborador::query()
                ->where('colaborador_id', $colaborador->getKey())
                ->where('estado', CambioServicioColaborador::PENDIENTE)
                ->exists();

            if ($enCurso) {
                throw new ExcepcionDeNegocioSimple("Ya hay una revisión de cambio de servicio en curso para {$colaborador->nombre_completo}. Continúala o cancélala antes de iniciar otra.");
            }

            if (! $this->custodia->tienePendientes($colaborador)) {
                $this->cambiarServicio->aplicar($colaborador, $servicioDestinoId, $motivo);

                return null;
            }

            $cambio = CambioServicioColaborador::query()->create([
                'colaborador_id' => $colaborador->getKey(),
                'empresa_id' => $colaborador->empresa_id,
                'servicio_origen_id' => $colaborador->servicio_actual_id,
                'servicio_destino_id' => $servicioDestinoId,
                'estado' => CambioServicioColaborador::PENDIENTE,
                'motivo' => $motivo,
                'iniciado_por' => $usuarioId,
                ...$this->cambios->cortesActuales(),
            ]);

            foreach ($this->cambios->renglonesDeCustodia($colaborador) as $renglon) {
                $cambio->renglones()->create($renglon);
            }

            $cambio->load(['servicioOrigen:id,nombre', 'servicioDestino:id,nombre']);

            $this->auditoria->registrar('colaboradores', 'iniciar_cambio_servicio', [
                'tipo_entidad' => CambioServicioColaborador::class,
                'entidad_id' => $cambio->getKey(),
                'empresa_id' => $colaborador->empresa_id,
                'descripcion' => sprintf(
                    'Revisión de custodia iniciada para el cambio de servicio de %s: %s → %s.',
                    $colaborador->nombre_completo,
                    $cambio->servicioOrigen->nombre ?? 'Sin servicio',
                    $cambio->servicioDestino->nombre ?? 'Sin servicio',
                ),
                'motivo' => $motivo,
                'valores_nuevos' => ['custodia_a_revisar' => $this->custodia->resumenLegible($colaborador)],
            ]);

            return $cambio;
        });
    }
}
