<?php

namespace App\Acciones;

use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\Area;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Models\TransferenciaColaborador;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioCustodiaColaborador;
use App\Soporte\GeneradorNumeroEmpleado;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Transfiere un colaborador de una empresa / razón social a otra CONSERVANDO
 * el mismo registro (id, expediente, foto, historial). Es una operación
 * distinta del cambio de servicio:
 *
 * - Bloquea si el colaborador tiene custodia pendiente (unidades asignadas o
 *   artículos por cantidad sin devolver) — nunca devuelve nada automáticamente.
 * - Genera un número de empleado NUEVO en la empresa de destino (la secuencia
 *   es por empresa; conservar el de origen podría colisionar).
 * - Limpia `servicio_actual_id` (un servicio pertenece a un contrato de la
 *   empresa de origen; en destino se asigna después).
 * - No toca inventario, unidades ni ningún registro histórico.
 *
 * Concurrencia: `lockForUpdate` sobre el colaborador. Es el mismo candado que
 * toma `CrearEntregaUniforme`, así que una entrega en curso y este cambio se
 * serializan: si la entrega gana, la custodia re-chequeada aquí lo detecta y
 * aborta; si gana el cambio, la entrega opera contra la empresa nueva.
 */
class CambiarEmpresaColaborador
{
    public function __construct(
        private readonly ServicioCustodiaColaborador $custodia,
        private readonly ServicioAuditoria $auditoria,
        private readonly GeneradorNumeroEmpleado $generadorNumeroEmpleado,
    ) {}

    public function ejecutar(
        Colaborador $colaborador,
        int $empresaDestinoId,
        int $sucursalDestinoId,
        ?int $areaDestinoId,
        string $motivo,
    ): Colaborador {
        return DB::transaction(function () use ($colaborador, $empresaDestinoId, $sucursalDestinoId, $areaDestinoId, $motivo): Colaborador {
            /** @var Colaborador $colaborador */
            $colaborador = Colaborador::query()->whereKey($colaborador->getKey())->lockForUpdate()->firstOrFail();
            $colaborador->loadMissing(['servicioActual', 'sucursal', 'departamento']);

            $empresaOrigen = Empresa::query()->findOrFail($colaborador->empresa_id);
            $servicioAnteriorId = $colaborador->servicio_actual_id;
            $servicioAnteriorNombre = 'Sin servicio';
            if ($colaborador->servicio_actual_id !== null) {
                $servicioAnteriorNombre = $colaborador->servicioActual->nombre;
            }
            $sucursalAnteriorId = $colaborador->sucursal_id;
            $sucursalAnteriorNombre = $colaborador->sucursal->nombre;
            $areaAnteriorId = $colaborador->area_id;
            $areaAnteriorNombre = $colaborador->departamento?->nombre;
            $numeroAnterior = $colaborador->numero_empleado;

            if ($empresaDestinoId === (int) $colaborador->empresa_id) {
                throw new ExcepcionDeNegocioSimple('El colaborador ya pertenece a esa empresa.');
            }

            $empresaDestino = Empresa::query()->where('activa', true)->findOr(
                $empresaDestinoId,
                fn () => throw new ExcepcionDeNegocioSimple('La empresa de destino no existe o está inactiva.'),
            );

            $sucursalDestino = Sucursal::query()
                ->where('empresa_id', $empresaDestino->id)
                ->where('activa', true)
                ->findOr($sucursalDestinoId, fn () => throw new ExcepcionDeNegocioSimple('La sucursal de destino no pertenece a la empresa elegida o está inactiva.'));

            $areaDestino = $areaDestinoId === null ? null : Area::query()
                ->where('empresa_id', $empresaDestino->id)
                ->where('activa', true)
                ->findOr($areaDestinoId, fn () => throw new ExcepcionDeNegocioSimple('El área de destino no pertenece a la empresa elegida o está inactiva.'));

            if ($this->custodia->tienePendientes($colaborador)) {
                throw new ExcepcionDeNegocioSimple(sprintf(
                    'No es posible cambiar a %s de %s a %s porque todavía tiene activos pendientes de devolución.',
                    $colaborador->nombre_completo,
                    $empresaOrigen->nombre_comercial,
                    $empresaDestino->nombre_comercial,
                ));
            }

            $numeroNuevo = $this->generadorNumeroEmpleado->generar($empresaDestino, $colaborador->nombre_completo);

            $colaborador->update([
                'empresa_id' => $empresaDestino->id,
                'sucursal_id' => $sucursalDestino->id,
                'area_id' => $areaDestino?->id,
                'area' => $areaDestino?->nombre,
                'servicio_actual_id' => null,
                'numero_empleado' => $numeroNuevo,
            ]);

            $this->auditoria->registrar('colaboradores', 'cambiar_empresa', [
                'tipo_entidad' => Colaborador::class,
                'entidad_id' => $colaborador->getKey(),
                'empresa_id' => $empresaDestino->id,
                'descripcion' => sprintf(
                    'Transferencia de %s: %s → %s.',
                    $colaborador->nombre_completo,
                    $empresaOrigen->nombre_comercial,
                    $empresaDestino->nombre_comercial,
                ),
                'motivo' => $motivo,
                // Etiquetas humanas (no `*_id`): `DescripcionAuditoria` oculta
                // los `*_id` sin resolver, así que el diff legible depende de
                // guardar nombres.
                'valores_anteriores' => [
                    'empresa' => $empresaOrigen->nombre_comercial,
                    'sucursal' => $sucursalAnteriorNombre,
                    'area' => $areaAnteriorNombre,
                    'servicio' => $servicioAnteriorNombre,
                    'numero_empleado' => $numeroAnterior,
                ],
                'valores_nuevos' => [
                    'empresa' => $empresaDestino->nombre_comercial,
                    'sucursal' => $sucursalDestino->nombre,
                    'area' => $areaDestino?->nombre,
                    'servicio' => 'Sin servicio',
                    'numero_empleado' => $numeroNuevo,
                ],
            ]);

            // Fila estructurada (IDs reales, no sólo nombres) para poder
            // reconstruir periodos con fechas exactas en el histórico laboral
            // del colaborador — ver App\Servicios\ServicioHistoricoColaborador.
            TransferenciaColaborador::query()->create([
                'colaborador_id' => $colaborador->getKey(),
                'empresa_origen_id' => $empresaOrigen->id,
                'empresa_destino_id' => $empresaDestino->id,
                'sucursal_origen_id' => $sucursalAnteriorId,
                'sucursal_destino_id' => $sucursalDestino->id,
                'area_origen_id' => $areaAnteriorId,
                'area_destino_id' => $areaDestino?->id,
                'servicio_origen_id' => $servicioAnteriorId,
                'servicio_destino_id' => null,
                'numero_empleado_anterior' => $numeroAnterior,
                'numero_empleado_nuevo' => $numeroNuevo,
                'motivo' => $motivo,
                'usuario_id' => Auth::id(),
                'ocurrido_en' => now(),
            ]);

            return $colaborador;
        });
    }
}
