<?php

namespace App\Acciones;

use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoUnidadActivo;
use App\Enums\TipoMovimiento;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\UnidadActivo;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\DB;

/**
 * Reporta la pérdida o el robo de una unidad ASIGNADA. NUNCA representa una
 * devolución física: la unidad NO vuelve a almacén, NO cuenta como stock, y
 * NO se limpia `colaborador_id` — se conserva el último responsable para
 * trazabilidad (sólo se limpia si la unidad se vuelve a asignar a otra
 * persona tras una recuperación explícita, `App\Acciones\RecuperarUnidadActivo`).
 */
class MarcarUnidadIncidencia
{
    public function __construct(
        private readonly ServicioInventario $inventario,
        private readonly ServicioAuditoria $auditoria,
    ) {}

    public function ejecutar(UnidadActivo $unidad, CondicionUnidadActivo $tipo, string $motivo, ?string $observacion, ?int $realizadoPor): UnidadActivo
    {
        if (! $tipo->esIncidencia()) {
            throw new ExcepcionDeNegocioSimple('El tipo de incidencia debe ser "Perdido" o "Robado".');
        }

        if (trim($motivo) === '') {
            throw new ExcepcionDeNegocioSimple('El motivo de la incidencia es obligatorio.');
        }

        return DB::transaction(function () use ($unidad, $tipo, $motivo, $observacion, $realizadoPor): UnidadActivo {
            $unidad = UnidadActivo::query()->whereKey($unidad->getKey())->lockForUpdate()->firstOrFail();

            if ($unidad->estado !== EstadoUnidadActivo::Asignada) {
                throw new ExcepcionDeNegocioSimple('Sólo se puede reportar pérdida o robo de una unidad que esté asignada a un colaborador.');
            }

            $antes = ['condicion' => $unidad->condicion->value];

            $unidad->update([
                'condicion' => $tipo,
                'observaciones' => $observacion,
                'incidencia_motivo' => $motivo,
                'incidencia_registrada_en' => now(),
                'incidencia_registrada_por' => $realizadoPor,
            ]);

            $this->inventario->registrarMovimientoUnidad(
                $unidad,
                TipoMovimiento::Incidencia,
                $realizadoPor,
                UnidadActivo::class,
                $unidad->getKey(),
                $motivo,
            );

            $this->auditoria->registrar('inventario', 'unidad_incidencia', [
                'tipo_entidad' => UnidadActivo::class,
                'entidad_id' => $unidad->getKey(),
                'empresa_id' => $unidad->empresa_id,
                'descripcion' => 'Unidad '.$unidad->codigo.' reportada como '.$tipo->etiqueta().': '.$motivo,
                'valores_anteriores' => $antes,
                'valores_nuevos' => ['condicion' => $tipo->value, 'motivo' => $motivo],
            ]);

            return $unidad;
        });
    }
}
