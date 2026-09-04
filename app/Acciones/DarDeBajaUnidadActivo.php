<?php

namespace App\Acciones;

use App\Enums\EstadoUnidadActivo;
use App\Enums\TipoMovimiento;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\UnidadActivo;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\DB;

/**
 * Baja definitiva de una unidad (fuera de operación, nunca hard-delete). No
 * es lo mismo que una incidencia de pérdida/robo: la baja es administrativa
 * (equipo desechado, dado de baja contablemente…) y siempre parte de una
 * unidad que YA no está asignada a nadie.
 */
class DarDeBajaUnidadActivo
{
    public function __construct(
        private readonly ServicioInventario $inventario,
        private readonly ServicioAuditoria $auditoria,
    ) {}

    public function ejecutar(UnidadActivo $unidad, string $motivo, ?int $realizadoPor): UnidadActivo
    {
        if ($unidad->estado === EstadoUnidadActivo::Baja) {
            throw new ExcepcionDeNegocioSimple('Esta unidad ya está dada de baja.');
        }
        if ($unidad->estado === EstadoUnidadActivo::Asignada) {
            throw new ExcepcionDeNegocioSimple('No puedes dar de baja una unidad asignada; regístrala primero como devolución o incidencia.');
        }

        return DB::transaction(function () use ($unidad, $motivo, $realizadoPor): UnidadActivo {
            $unidad->update([
                'estado' => EstadoUnidadActivo::Baja,
                'dado_de_baja_en' => now(),
                'motivo_baja' => $motivo,
            ]);

            $this->inventario->registrarMovimientoUnidad(
                unidad: $unidad,
                tipo: TipoMovimiento::Baja,
                realizadoPor: $realizadoPor,
                referenciaTipo: 'baja_unidad',
                motivo: $motivo,
            );

            $this->auditoria->registrar('inventario', 'unidad_baja', [
                'empresa_id' => $unidad->empresa_id,
                'tipo_entidad' => UnidadActivo::class,
                'entidad_id' => $unidad->id,
                'descripcion' => 'Baja de la unidad '.$unidad->codigo.'. Motivo: '.$motivo,
            ]);

            return $unidad->fresh();
        });
    }
}
