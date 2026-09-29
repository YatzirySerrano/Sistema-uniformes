<?php

namespace App\Acciones;

use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\Colaborador;
use App\Models\Servicio;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioCustodiaColaborador;
use Illuminate\Support\Facades\DB;

/**
 * Cambia ÚNICAMENTE la ubicación operativa VIGENTE del colaborador
 * (`servicio_actual_id`). Es una acción independiente y deliberadamente
 * mínima: NO crea ni modifica entregas, NO crea devoluciones, NO toca
 * inventario/stock/almacén, NO cambia asignaciones de activos ni unidades.
 *
 * La ubicación operativa de lo que el colaborador tiene asignado se deriva en
 * vivo de `colaborador->servicioActual`. Por eso, si el colaborador SALE de
 * un servicio (a otro o a "sin servicio") mientras conserva custodia
 * pendiente, el cambio se RECHAZA: de lo contrario el microondas que recibió
 * en Palmira "aparecería" en Cuernavaca sin que nadie lo moviera. Primero se
 * resuelve la custodia con operaciones reales (devolución al almacén o
 * redistribución a quien quede responsable) y después se cambia el servicio.
 * Asignar un servicio a quien no tenía ninguno no mueve nada de un servicio
 * a otro y no se bloquea. La regla de "¿tiene custodia pendiente?" es la
 * misma fuente que usa el cambio de empresa (`ServicioCustodiaColaborador`).
 */
class CambiarServicioColaborador
{
    public function __construct(
        private readonly ServicioAuditoria $auditoria,
        private readonly ServicioCustodiaColaborador $custodia,
    ) {}

    public function ejecutar(Colaborador $colaborador, ?int $servicioId, ?string $motivo = null): Colaborador
    {
        return DB::transaction(function () use ($colaborador, $servicioId, $motivo): Colaborador {
            /** @var Colaborador $colaborador */
            $colaborador = Colaborador::query()->whereKey($colaborador->getKey())->lockForUpdate()->firstOrFail();

            // Re-chequeo AUTORITATIVO bajo el mismo candado que toman las
            // entregas/redistribuciones sobre el colaborador: nada puede
            // entrar a su custodia entre esta verificación y el cambio.
            $saleDeUnServicio = $colaborador->servicio_actual_id !== null
                && $colaborador->servicio_actual_id !== $servicioId;

            if ($saleDeUnServicio && $this->custodia->tienePendientes($colaborador)) {
                throw new ExcepcionDeNegocioSimple(sprintf(
                    'No es posible cambiar a %s de servicio mientras tenga bienes bajo custodia (%s). Devuélvelos al almacén o entrégalos a quien quede como responsable y vuelve a intentarlo.',
                    $colaborador->nombre_completo,
                    implode('; ', $this->custodia->resumenLegible($colaborador)),
                ));
            }

            $servicioAnterior = $colaborador->servicio_actual_id !== null
                ? Servicio::query()->with('contrato')->find($colaborador->servicio_actual_id)
                : null;
            $servicioNuevo = $servicioId !== null
                ? Servicio::query()->with('contrato')->find($servicioId)
                : null;

            $colaborador->update(['servicio_actual_id' => $servicioId]);

            $this->auditoria->registrar('colaboradores', 'cambiar_servicio', [
                'tipo_entidad' => Colaborador::class,
                'entidad_id' => $colaborador->getKey(),
                'empresa_id' => $colaborador->empresa_id,
                'descripcion' => 'Cambio de servicio de '.$colaborador->nombre_completo.': '
                    .$this->etiquetaServicio($servicioAnterior).' → '.$this->etiquetaServicio($servicioNuevo),
                'motivo' => $motivo,
                // Claves humanas (no `*_id`): `DescripcionAuditoria` oculta
                // cualquier campo `*_id` sin resolverlo, así que el diff
                // legible depende de guardar nombre de servicio/contrato.
                'valores_anteriores' => [
                    'servicio' => $this->etiquetaServicio($servicioAnterior),
                    'contrato' => $this->nombreContrato($servicioAnterior),
                ],
                'valores_nuevos' => [
                    'servicio' => $this->etiquetaServicio($servicioNuevo),
                    'contrato' => $this->nombreContrato($servicioNuevo),
                ],
            ]);

            return $colaborador;
        });
    }

    private function etiquetaServicio(?Servicio $servicio): string
    {
        return $servicio === null ? 'Sin servicio' : $servicio->nombre;
    }

    private function nombreContrato(?Servicio $servicio): ?string
    {
        return $servicio?->contrato->nombre;
    }
}
