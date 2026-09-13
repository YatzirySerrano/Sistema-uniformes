<?php

namespace App\Acciones;

use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoUnidadActivo;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\UnidadActivo;
use App\Servicios\ServicioAuditoria;
use Illuminate\Support\Facades\DB;

/**
 * Único camino explícito para que una unidad "En reparación" o "Inservible"
 * vuelva a una condición operativa (normalmente Funcionando) — mismo espíritu
 * que `RecuperarUnidadActivo`, pero para el otro grupo de condiciones no
 * entregables (nunca pérdida/robo, eso sigue siendo `RecuperarUnidadActivo`).
 *
 * NUNCA cambia `codigo`, `public_token` ni crea una unidad nueva: es la MISMA
 * unidad física, sólo cambia su condición. No toca `estado`/`almacen_id`
 * porque la unidad nunca salió del almacén (no es un movimiento de stock,
 * por eso no genera `MovimientoInventario` — sólo bitácora de auditoría).
 * Sólo aplica a una unidad EnAlmacén y no asignada; sea cual sea la condición
 * resultante, `UnidadActivo::esEntregable()` decide después si ya puede
 * volver a asignarse (misma regla central que usa todo el sistema).
 */
class RestaurarCondicionUnidadActivo
{
    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    public function ejecutar(UnidadActivo $unidad, CondicionUnidadActivo $condicionResultante, ?string $notas, ?int $realizadoPor): UnidadActivo
    {
        if ($condicionResultante->esIncidencia()) {
            throw new ExcepcionDeNegocioSimple('La condición resultante no puede ser pérdida/robo; eso se reporta como incidencia, no se "restaura".');
        }

        return DB::transaction(function () use ($unidad, $condicionResultante, $notas): UnidadActivo {
            $unidad = UnidadActivo::query()->whereKey($unidad->getKey())->lockForUpdate()->firstOrFail();

            if ($unidad->estado !== EstadoUnidadActivo::EnAlmacen) {
                throw new ExcepcionDeNegocioSimple('Sólo se puede restaurar la condición de una unidad que está en almacén (no asignada ni dada de baja).');
            }

            if (! in_array($unidad->condicion, [CondicionUnidadActivo::EnReparacion, CondicionUnidadActivo::Inservible], true)) {
                throw new ExcepcionDeNegocioSimple('Sólo se puede restaurar la condición de una unidad "En reparación" o "Inservible".');
            }

            $antes = ['condicion' => $unidad->condicion->value];

            $unidad->update(['condicion' => $condicionResultante]);

            $this->auditoria->registrar('inventario', 'unidad_restaurar_condicion', [
                'tipo_entidad' => UnidadActivo::class,
                'entidad_id' => $unidad->getKey(),
                'empresa_id' => $unidad->empresa_id,
                'descripcion' => 'Unidad '.$unidad->codigo.' restaurada a condición «'.$condicionResultante->etiqueta().'».',
                'valores_anteriores' => $antes,
                'valores_nuevos' => ['condicion' => $condicionResultante->value],
                'motivo' => $notas,
            ]);

            return $unidad->fresh();
        });
    }
}
