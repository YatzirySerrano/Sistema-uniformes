<?php

namespace App\Acciones;

use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoUnidadActivo;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\UnidadActivo;
use App\Servicios\ServicioAuditoria;
use Illuminate\Support\Facades\DB;

/**
 * Único camino explícito para marcar como dañada ("En reparación" /
 * "Inservible") una unidad Funcionando que está en almacén — reverso de
 * `RestaurarCondicionUnidadActivo`. Nunca representa pérdida/robo, eso lo
 * cubre `MarcarUnidadIncidencia`. No toca `estado`/`almacen_id`: la unidad no
 * sale del almacén, sólo cambia su condición física, por eso tampoco genera
 * `MovimientoInventario` — mismo criterio que su acción inversa.
 */
class MarcarCondicionUnidadActivo
{
    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    public function ejecutar(UnidadActivo $unidad, CondicionUnidadActivo $condicionResultante, string $motivo, ?int $realizadoPor): UnidadActivo
    {
        if ($condicionResultante->esIncidencia() || $condicionResultante === CondicionUnidadActivo::Funcionando) {
            throw new ExcepcionDeNegocioSimple('La condición resultante debe ser "En reparación" o "Inservible".');
        }
        if (trim($motivo) === '') {
            throw new ExcepcionDeNegocioSimple('El motivo del daño es obligatorio.');
        }

        return DB::transaction(function () use ($unidad, $condicionResultante, $motivo): UnidadActivo {
            $unidad = UnidadActivo::query()->whereKey($unidad->getKey())->lockForUpdate()->firstOrFail();

            if ($unidad->estado !== EstadoUnidadActivo::EnAlmacen) {
                throw new ExcepcionDeNegocioSimple('Sólo se puede marcar como dañada una unidad que está en almacén.');
            }
            if ($unidad->condicion !== CondicionUnidadActivo::Funcionando) {
                throw new ExcepcionDeNegocioSimple('Esta unidad ya no está funcionando; revisa su condición actual.');
            }

            $antes = ['condicion' => $unidad->condicion->value];

            $unidad->update(['condicion' => $condicionResultante, 'observaciones' => $motivo]);

            $this->auditoria->registrar('inventario', 'unidad_marcar_danada', [
                'tipo_entidad' => UnidadActivo::class,
                'entidad_id' => $unidad->getKey(),
                'empresa_id' => $unidad->empresa_id,
                'descripcion' => 'Unidad '.$unidad->codigo.' marcada como «'.$condicionResultante->etiqueta().'». Motivo: '.$motivo,
                'valores_anteriores' => $antes,
                'valores_nuevos' => ['condicion' => $condicionResultante->value],
                'motivo' => $motivo,
            ]);

            return $unidad->fresh();
        });
    }
}
