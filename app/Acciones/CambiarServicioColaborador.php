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
 * (`servicio_actual_id`). NO crea ni modifica entregas, NO crea devoluciones,
 * NO toca inventario/stock/almacén, NO cambia asignaciones de activos.
 *
 * La ubicación operativa de lo que el colaborador tiene asignado se deriva en
 * vivo de `colaborador->servicioActual`. Por eso el camino DIRECTO
 * (`ejecutar()`) sólo procede si el colaborador no tiene custodia: con
 * custodia, cualquier cambio real (A → B, A → sin servicio, sin servicio → A)
 * pasa por la revisión de custodia (`IniciarCambioServicio` /
 * `CompletarCambioServicio`), donde se decide bien por bien si se mantiene,
 * se devuelve o se redistribuye, y sólo entonces se aplica el cambio con
 * `aplicar()`. La regla "¿tiene custodia pendiente?" es la misma fuente que
 * usa el cambio de empresa (`ServicioCustodiaColaborador`).
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
            if ($colaborador->servicio_actual_id !== $servicioId && $this->custodia->tienePendientes($colaborador)) {
                throw new ExcepcionDeNegocioSimple(sprintf(
                    '%s tiene bienes bajo custodia (%s). Antes de cambiar su servicio revisa qué se mantiene con él, qué se devuelve y qué se redistribuye.',
                    $colaborador->nombre_completo,
                    implode('; ', $this->custodia->resumenLegible($colaborador)),
                ));
            }

            return $this->aplicar($colaborador, $servicioId, $motivo);
        });
    }

    /**
     * Aplica el cambio y lo audita. El llamador YA tiene bloqueado al
     * colaborador y ya verificó la custodia (camino directo sin custodia, o
     * revisión de custodia completada — cuyo resumen legible llega en
     * `$custodiaRevisada` para dejarlo en la misma bitácora).
     *
     * @param  array{mantener: list<string>, devuelto: list<string>, redistribuido: list<string>}|null  $custodiaRevisada
     */
    public function aplicar(Colaborador $colaboradorBloqueado, ?int $servicioId, ?string $motivo = null, ?array $custodiaRevisada = null): Colaborador
    {
        $servicioAnterior = $colaboradorBloqueado->servicio_actual_id !== null
            ? Servicio::query()->with('contrato')->find($colaboradorBloqueado->servicio_actual_id)
            : null;
        $servicioNuevo = $servicioId !== null
            ? Servicio::query()->with('contrato')->find($servicioId)
            : null;

        $colaboradorBloqueado->update(['servicio_actual_id' => $servicioId]);

        $this->auditoria->registrar('colaboradores', 'cambiar_servicio', [
            'tipo_entidad' => Colaborador::class,
            'entidad_id' => $colaboradorBloqueado->getKey(),
            'empresa_id' => $colaboradorBloqueado->empresa_id,
            'descripcion' => 'Cambio de servicio de '.$colaboradorBloqueado->nombre_completo.': '
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
                // Sólo si hubo revisión de custodia: qué acompaña al
                // colaborador, qué se devolvió y qué se redistribuyó.
                ...array_filter([
                    'custodia_mantenida' => $custodiaRevisada['mantener'] ?? [],
                    'custodia_devuelta' => $custodiaRevisada['devuelto'] ?? [],
                    'custodia_redistribuida' => $custodiaRevisada['redistribuido'] ?? [],
                ], fn (array $lineas): bool => $lineas !== []),
            ],
        ]);

        return $colaboradorBloqueado;
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
