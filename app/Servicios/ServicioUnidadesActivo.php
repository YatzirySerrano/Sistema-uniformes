<?php

namespace App\Servicios;

use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoUnidadActivo;
use App\Enums\TipoMovimiento;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\UnidadActivo;
use Illuminate\Database\Eloquent\Collection;

/**
 * Ciclo Disponible ⇄ Asignada de unidades de seguimiento individual para
 * Entregas/Devoluciones. Complementa a `ServicioInventario`: aquí no hay
 * saldo agregado que bloquear, sino filas concretas de `unidades_activo` que
 * se bloquean una a una (`lockForUpdate`) para evitar doble asignación
 * concurrente. Perdido/Robado y su recuperación NO pasan por aquí — son
 * incidencias (`App\Acciones\MarcarUnidadIncidencia` /
 * `App\Acciones\RecuperarUnidadActivo`), nunca una devolución.
 */
class ServicioUnidadesActivo
{
    public function __construct(private readonly ServicioInventario $inventario) {}

    /**
     * Bloquea y devuelve una unidad concreta, verificando que siga entregable
     * en el momento exacto de la transacción (defensa contra condiciones de
     * carrera: dos entregas compitiendo por la misma unidad).
     */
    public function bloquearYVerificarEntregable(int $unidadId): UnidadActivo
    {
        $unidad = UnidadActivo::query()->whereKey($unidadId)->lockForUpdate()->first();

        if (! $unidad instanceof UnidadActivo || ! $unidad->esEntregable()) {
            throw new ExcepcionDeNegocioSimple('La unidad seleccionada ya no está disponible para entrega.');
        }

        return $unidad;
    }

    /**
     * Bloquea y reserva las primeras N unidades entregables de un activo en
     * un almacén (orden estable por id), excluyendo las que ya se reservaron
     * en la misma operación (p. ej. otro componente de seguimiento individual
     * de la misma entrega). Lanza si no alcanza la cantidad solicitada.
     *
     * @param  array<int, int>  $excluirIds
     * @return Collection<int, UnidadActivo>
     */
    public function reservarDisponibles(int $activoId, int $almacenId, int $cantidad, array $excluirIds = []): Collection
    {
        $unidades = UnidadActivo::query()
            ->where('activo_id', $activoId)
            ->where('almacen_id', $almacenId)
            ->where('estado', EstadoUnidadActivo::EnAlmacen)
            ->where('condicion', CondicionUnidadActivo::Funcionando)
            ->when($excluirIds !== [], fn ($q) => $q->whereNotIn('id', $excluirIds))
            ->orderBy('id')
            ->lockForUpdate()
            ->limit($cantidad)
            ->get();

        if ($unidades->count() < $cantidad) {
            throw new ExcepcionDeNegocioSimple(sprintf(
                'No hay suficientes unidades disponibles de este activo en el almacén seleccionado. Disponibles: %d, solicitadas: %d.',
                $unidades->count(),
                $cantidad,
            ));
        }

        return $unidades;
    }

    /**
     * Asigna la unidad a un colaborador (Entrega). El llamador ya bloqueó y
     * verificó la unidad con `bloquearYVerificarEntregable()`/`reservarDisponibles()`.
     */
    public function asignar(
        UnidadActivo $unidad,
        int $colaboradorId,
        ?int $realizadoPor,
        string $referenciaTipo,
        int $referenciaId,
        ?string $motivo = null,
        ?int $sucursalId = null,
    ): void {
        $unidad->update(['estado' => EstadoUnidadActivo::Asignada, 'colaborador_id' => $colaboradorId]);

        $this->inventario->registrarMovimientoUnidad(
            $unidad,
            TipoMovimiento::Entrega,
            $realizadoPor,
            $referenciaTipo,
            $referenciaId,
            $motivo,
            sucursalId: $sucursalId,
        );
    }

    /**
     * Devolución física normal: la unidad vuelve a almacén y se limpia el
     * responsable. La condición resultante gobierna si vuelve a ser
     * entregable (`Funcionando`) o queda en almacén pero no entregable
     * (`EnReparacion`/`Inservible`). Nunca acepta Perdido/Robado — eso es una
     * incidencia, no una devolución.
     */
    public function devolver(
        UnidadActivo $unidad,
        CondicionUnidadActivo $condicionResultante,
        int $almacenDestinoId,
        ?int $realizadoPor,
        string $referenciaTipo,
        int $referenciaId,
        ?string $motivo = null,
        ?int $sucursalId = null,
    ): void {
        if ($unidad->estado !== EstadoUnidadActivo::Asignada) {
            throw new ExcepcionDeNegocioSimple('Esta unidad no está asignada actualmente; no se puede devolver.');
        }

        if ($condicionResultante->esIncidencia()) {
            throw new ExcepcionDeNegocioSimple('Pérdida o robo no se registra como devolución. Usa "Reportar incidencia" desde la unidad.');
        }

        $unidad->update([
            'estado' => EstadoUnidadActivo::EnAlmacen,
            'condicion' => $condicionResultante,
            'almacen_id' => $almacenDestinoId,
            'colaborador_id' => null,
        ]);

        $this->inventario->registrarMovimientoUnidad(
            $unidad,
            TipoMovimiento::Devolucion,
            $realizadoPor,
            $referenciaTipo,
            $referenciaId,
            $motivo,
            sucursalId: $sucursalId,
        );
    }
}
