<?php

namespace App\Acciones;

use App\Models\Colaborador;
use App\Models\Servicio;
use App\Servicios\ServicioAuditoria;
use Illuminate\Support\Facades\DB;

/**
 * Cambia ÚNICAMENTE la ubicación operativa VIGENTE del colaborador
 * (`servicio_actual_id`). Es una acción independiente y deliberadamente
 * mínima: NO crea ni modifica entregas, NO crea devoluciones, NO toca
 * inventario/stock/almacén, NO cambia asignaciones de activos ni unidades.
 * Los activos que el colaborador ya tiene asignados reflejan la nueva
 * ubicación automáticamente porque su "ubicación operativa" siempre se
 * deriva en vivo de `colaborador->servicioActual` — no hay nada más que
 * sincronizar.
 */
class CambiarServicioColaborador
{
    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    public function ejecutar(Colaborador $colaborador, ?int $servicioId, ?string $motivo = null): Colaborador
    {
        return DB::transaction(function () use ($colaborador, $servicioId, $motivo): Colaborador {
            /** @var Colaborador $colaborador */
            $colaborador = Colaborador::query()->whereKey($colaborador->getKey())->lockForUpdate()->firstOrFail();

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
