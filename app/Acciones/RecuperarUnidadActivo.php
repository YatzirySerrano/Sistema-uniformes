<?php

namespace App\Acciones;

use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoUnidadActivo;
use App\Enums\TipoMovimiento;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\Almacen;
use App\Models\UnidadActivo;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\DB;

/**
 * Único camino para que una unidad marcada Perdido/Robado vuelva a operar:
 * acción explícita ("Recuperar / reingresar"), nunca una transición
 * silenciosa desde otro flujo. Pide el almacén de destino y la condición
 * resultante (nunca otra incidencia). El último responsable (`colaborador_id`)
 * se conserva hasta que la unidad se vuelva a asignar a alguien más.
 */
class RecuperarUnidadActivo
{
    public function __construct(
        private readonly ServicioInventario $inventario,
        private readonly ServicioAuditoria $auditoria,
    ) {}

    public function ejecutar(UnidadActivo $unidad, Almacen $almacenDestino, CondicionUnidadActivo $condicionResultante, ?string $notas, ?int $realizadoPor): UnidadActivo
    {
        if ($condicionResultante->esIncidencia()) {
            throw new ExcepcionDeNegocioSimple('La condición resultante de una recuperación no puede ser otra incidencia (pérdida/robo).');
        }

        if (! $almacenDestino->activo || ! $almacenDestino->abasteceEmpresa($unidad->empresa_id)) {
            throw new ExcepcionDeNegocioSimple("El almacén «{$almacenDestino->nombre}» no está activo o no abastece a la empresa de esta unidad.");
        }

        return DB::transaction(function () use ($unidad, $almacenDestino, $condicionResultante, $notas, $realizadoPor): UnidadActivo {
            $unidad = UnidadActivo::query()->whereKey($unidad->getKey())->lockForUpdate()->firstOrFail();

            if (! $unidad->condicion->esIncidencia()) {
                throw new ExcepcionDeNegocioSimple('Sólo se pueden recuperar unidades marcadas como pérdida o robo.');
            }

            $antes = ['estado' => $unidad->estado->value, 'condicion' => $unidad->condicion->value];

            $unidad->update([
                'estado' => EstadoUnidadActivo::EnAlmacen,
                'condicion' => $condicionResultante,
                'almacen_id' => $almacenDestino->getKey(),
            ]);

            $this->inventario->registrarMovimientoUnidad(
                $unidad,
                TipoMovimiento::Recuperacion,
                $realizadoPor,
                UnidadActivo::class,
                $unidad->getKey(),
                'Recuperación de unidad',
                $notas,
            );

            $this->auditoria->registrar('inventario', 'unidad_recuperacion', [
                'tipo_entidad' => UnidadActivo::class,
                'entidad_id' => $unidad->getKey(),
                'empresa_id' => $unidad->empresa_id,
                'descripcion' => 'Unidad '.$unidad->codigo.' recuperada al almacén '.$almacenDestino->nombre,
                'valores_anteriores' => $antes,
                'valores_nuevos' => ['estado' => $unidad->estado->value, 'condicion' => $condicionResultante->value, 'almacen_id' => $almacenDestino->getKey()],
            ]);

            return $unidad;
        });
    }
}
