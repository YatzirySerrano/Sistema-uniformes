<?php

namespace App\Servicios;

use App\Enums\EstadoDevolucion;
use App\Enums\EstadoUnidadActivo;
use App\Models\Colaborador;
use App\Models\DetalleDevolucion;
use App\Models\DetalleEntrega;
use App\Models\EntregaUniforme;
use App\Models\UnidadActivo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Fuente ÚNICA de verdad de la "custodia pendiente": qué activos tiene
 * todavía físicamente en su poder un colaborador (o una entrega concreta) y
 * aún no se ha devuelto por el flujo formal. La usan la previsualización del
 * cambio de empresa, el re-chequeo bajo lock de `CambiarEmpresaColaborador`,
 * el selector de "Entrega de origen" de Devoluciones (`EntregaController::buscar`)
 * y la validación al registrar una devolución (`RegistrarDevolucion`) — un solo
 * algoritmo, nunca duplicado.
 *
 * Regla de negocio:
 * - Artículos por CANTIDAD: por cada renglón de una entrega firmada/corregida,
 *   `pendiente = entregado − devuelto CONFIRMADO`. Una devolución todavía
 *   `pendiente_firma` NO libera custodia ni cuenta como devuelta (el
 *   colaborador sigue teniendo el artículo hasta que ambas firmas concreten
 *   la devolución).
 * - UNIDADES identificadas: toda unidad con `colaborador_id = X` y
 *   `estado = Asignada` — cubre asignadas normales y también perdidas/robadas
 *   (`MarcarUnidadIncidencia` conserva `estado = Asignada`): una responsabilidad
 *   abierta bloquea el traslado hasta resolverse por su flujo correcto.
 *
 * Nunca crea devoluciones ni mueve inventario: sólo LEE y clasifica.
 */
class ServicioCustodiaColaborador
{
    /**
     * ¿El colaborador tiene algo pendiente de devolución? Versión barata para
     * el gate (no arma la lista completa).
     */
    public function tienePendientes(Colaborador $colaborador): bool
    {
        $tieneUnidad = UnidadActivo::query()
            ->where('colaborador_id', $colaborador->getKey())
            ->where('estado', EstadoUnidadActivo::Asignada)
            ->exists();

        if ($tieneUnidad) {
            return true;
        }

        return $this->cantidadesPendientes($colaborador) !== [];
    }

    /**
     * Detalle legible de la custodia pendiente, para la previsualización del
     * wizard de transferencia y para contextualizar "Nueva devolución" desde
     * ahí: una fila por unidad identificada + una fila por renglón de
     * cantidad con saldo pendiente, agrupable por entrega de origen mediante
     * `entrega_id`/`entrega_folio`. Trae SIEMPRE los IDs reales — nunca sólo
     * el folio — para que el frontend pueda enlazar directo sin que el
     * usuario tenga que memorizar ni volver a buscar nada.
     *
     * @return list<array{tipo: string, tipo_etiqueta: string, activo: string, talla: string|null, cantidad: int, referencia: string|null, entrega_id: int|null, entrega_folio: string|null, detalle_entrega_id: int|null, unidad_activo_id: int|null}>
     */
    public function pendientes(Colaborador $colaborador): array
    {
        $unidades = UnidadActivo::query()
            ->where('colaborador_id', $colaborador->getKey())
            ->where('estado', EstadoUnidadActivo::Asignada)
            ->with('activo:id,nombre')
            ->orderBy('codigo')
            ->get()
            ->map(function (UnidadActivo $u): array {
                $detalle = $this->entregaActualDeUnidad($u);

                return [
                    'tipo' => 'unidad',
                    'tipo_etiqueta' => 'Unidad identificada',
                    'activo' => $u->activo->nombre,
                    'talla' => null,
                    'cantidad' => 1,
                    'referencia' => $u->codigo,
                    'entrega_id' => $detalle?->entrega_uniforme_id,
                    'entrega_folio' => $detalle?->entrega?->folio,
                    'detalle_entrega_id' => $detalle?->id,
                    'unidad_activo_id' => $u->getKey(),
                ];
            })
            ->all();

        $cantidades = array_map(fn (array $fila): array => [
            'tipo' => 'cantidad',
            'tipo_etiqueta' => 'Artículo por cantidad',
            'activo' => $fila['activo'],
            'talla' => $fila['talla'],
            'cantidad' => $fila['pendiente'],
            'referencia' => $fila['folio'],
            'entrega_id' => $fila['entrega_id'],
            'entrega_folio' => $fila['folio'],
            'detalle_entrega_id' => $fila['detalle_entrega_id'],
            'unidad_activo_id' => null,
        ], $this->cantidadesPendientes($colaborador));

        return array_merge($unidades, $cantidades);
    }

    /**
     * Pendiente real de UN renglón de entrega por cantidad: entregado menos
     * lo devuelto en devoluciones CONFIRMADAS. Fuente única reutilizada por
     * `RegistrarDevolucion` (validación al registrar) y `DevolucionController`
     * (presentación del formulario) — nunca vuelvas a sumar `DetalleDevolucion`
     * a mano fuera de aquí.
     */
    public function pendienteDeDetalle(DetalleEntrega $detalle): int
    {
        return $this->pendientesPorDetalle(collect([$detalle]))[$detalle->getKey()] ?? 0;
    }

    /**
     * Versión en lote de `pendienteDeDetalle()` para evitar N+1 al presentar
     * todos los renglones de una entrega o de un colaborador a la vez.
     *
     * @param  Collection<int, DetalleEntrega>  $detalles
     * @return array<int, int> pendiente indexado por `detalle_entrega_id`
     */
    public function pendientesPorDetalle(Collection $detalles): array
    {
        if ($detalles->isEmpty()) {
            return [];
        }

        $devueltoPorDetalle = DetalleDevolucion::query()
            ->whereIn('detalle_entrega_id', $detalles->pluck('id'))
            ->whereHas('devolucion', fn ($q) => $q->where('estado', EstadoDevolucion::Confirmada))
            ->selectRaw('detalle_entrega_id, SUM(cantidad) as total')
            ->groupBy('detalle_entrega_id')
            ->pluck('total', 'detalle_entrega_id');

        return $detalles
            ->mapWithKeys(fn (DetalleEntrega $d): array => [
                $d->getKey() => max((int) $d->cantidad - (int) ($devueltoPorDetalle[$d->getKey()] ?? 0), 0),
            ])
            ->all();
    }

    /**
     * Filtra una query de `EntregaUniforme` para dejar sólo las que TODAVÍA
     * tienen custodia pendiente real (al menos un renglón por cantidad con
     * saldo, o al menos una unidad identificada todavía asignada). Es el
     * filtro que debe alimentar cualquier selector de "Entrega de origen":
     * una entrega totalmente devuelta y confirmada nunca debe volver a
     * ofrecerse. Correlacionado en SQL, sin N+1.
     *
     * @param  Builder<EntregaUniforme>  $query
     * @return Builder<EntregaUniforme>
     */
    public function filtrarConPendiente(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereHas('detalles', function (Builder $d) {
                $d->whereNull('unidad_activo_id')
                    ->whereRaw('detalles_entrega.cantidad > (
                        select coalesce(sum(dd.cantidad), 0)
                        from detalles_devolucion dd
                        inner join devoluciones dv on dv.id = dd.devolucion_id
                        where dd.detalle_entrega_id = detalles_entrega.id
                        and dv.estado = ?
                    )', [EstadoDevolucion::Confirmada->value]);
            })->orWhereHas('detalles', function (Builder $d) {
                $d->whereNotNull('unidad_activo_id')
                    ->whereHas('unidadActivo', fn (Builder $u) => $u->where('estado', EstadoUnidadActivo::Asignada));
            });
        });
    }

    /**
     * Renglón de entrega bajo el que una unidad identificada está
     * ACTUALMENTE asignada: el más reciente que todavía no tiene una
     * devolución CONFIRMADA. Una unidad puede haberse entregado varias veces
     * a lo largo de su vida (entregada → devuelta → vuelta a entregar); esto
     * localiza la entrega vigente, no todo el historial.
     */
    public function entregaActualDeUnidad(UnidadActivo $unidad): ?DetalleEntrega
    {
        return DetalleEntrega::query()
            ->where('unidad_activo_id', $unidad->getKey())
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('detalles_devolucion as dd')
                    ->join('devoluciones as dv', 'dv.id', '=', 'dd.devolucion_id')
                    ->whereColumn('dd.detalle_entrega_id', 'detalles_entrega.id')
                    ->where('dv.estado', EstadoDevolucion::Confirmada->value);
            })
            ->with('entrega:id,folio')
            ->latest('id')
            ->first();
    }

    /**
     * KPI "Activos asignados": total de PIEZAS FÍSICAS que el colaborador
     * tiene actualmente bajo custodia — una unidad identificada cuenta como 1
     * pieza; un renglón de cantidad cuenta su saldo pendiente (entregado −
     * devuelto CONFIRMADO). Es la cantidad física, no el número de renglones
     * distintos: 3 playeras entregadas en un solo renglón cuentan como 3.
     * Reutiliza exactamente los mismos criterios que `pendientes()`, sin
     * volver a armar el detalle legible (evita el `with('activo')` y el join
     * de `entregaActualDeUnidad()` por unidad, innecesarios para un total).
     *
     * @param  array<int, int>|null  $idsEmpresasAutorizadas  Aislamiento histórico (ver `ColaboradorController::show`): `null` = sin acotar (uso interno/negocio), un arreglo = sólo cuenta lo que esas empresas autorizan.
     */
    public function totalPiezasPendientes(Colaborador $colaborador, ?array $idsEmpresasAutorizadas = null): int
    {
        $unidades = UnidadActivo::query()
            ->where('colaborador_id', $colaborador->getKey())
            ->where('estado', EstadoUnidadActivo::Asignada)
            ->when($idsEmpresasAutorizadas !== null, fn (Builder $q) => $q->whereIn('empresa_id', $idsEmpresasAutorizadas))
            ->count();

        $cantidad = array_sum(array_column($this->cantidadesPendientes($colaborador, $idsEmpresasAutorizadas), 'pendiente'));

        return $unidades + $cantidad;
    }

    /**
     * Renglones de cantidad con saldo pendiente de devolución de un
     * colaborador (todas sus entregas firmadas/corregidas).
     *
     * @param  array<int, int>|null  $idsEmpresasAutorizadas  Acota a estas empresas cuando no es `null` — ver `totalPiezasPendientes()`.
     * @return list<array{activo: string, talla: string|null, pendiente: int, folio: string|null, entrega_id: int|null, detalle_entrega_id: int}>
     */
    private function cantidadesPendientes(Colaborador $colaborador, ?array $idsEmpresasAutorizadas = null): array
    {
        /** @var Collection<int, DetalleEntrega> $detalles */
        $detalles = DetalleEntrega::query()
            ->whereNull('unidad_activo_id')
            ->whereHas('entrega', fn ($q) => $q
                ->where('colaborador_id', $colaborador->getKey())
                ->whereIn('estado', ['firmada', 'corregida'])
                ->when($idsEmpresasAutorizadas !== null, fn ($q2) => $q2->whereIn('empresa_id', $idsEmpresasAutorizadas)))
            ->with('entrega:id,folio')
            ->get();

        if ($detalles->isEmpty()) {
            return [];
        }

        $pendientePorDetalle = $this->pendientesPorDetalle($detalles);

        $filas = [];

        foreach ($detalles as $detalle) {
            $pendiente = $pendientePorDetalle[$detalle->id] ?? 0;

            if ($pendiente <= 0) {
                continue;
            }

            $filas[] = [
                'activo' => $detalle->activo_nombre_snapshot,
                'talla' => $detalle->talla_valor_snapshot,
                'pendiente' => $pendiente,
                'folio' => $detalle->entrega?->folio,
                'entrega_id' => $detalle->entrega_uniforme_id,
                'detalle_entrega_id' => $detalle->id,
            ];
        }

        return $filas;
    }
}
