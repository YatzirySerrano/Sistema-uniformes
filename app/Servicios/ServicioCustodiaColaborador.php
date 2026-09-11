<?php

namespace App\Servicios;

use App\Enums\EstadoDevolucion;
use App\Enums\EstadoUnidadActivo;
use App\Models\Colaborador;
use App\Models\DetalleDevolucion;
use App\Models\DetalleEntrega;
use App\Models\UnidadActivo;
use Illuminate\Support\Collection;

/**
 * Fuente ÚNICA de verdad de la "custodia pendiente" de un colaborador: qué
 * activos tiene todavía físicamente en su poder y aún no ha devuelto por el
 * flujo formal. Se usa tanto para la previsualización del cambio de empresa
 * como para el re-chequeo bajo lock dentro de `CambiarEmpresaColaborador`.
 *
 * Regla de negocio:
 * - Artículos por CANTIDAD: por cada renglón de una entrega firmada/corregida
 *   del colaborador, `pendiente = entregado − devuelto CONFIRMADO`. Una
 *   devolución todavía `pendiente_firma` NO libera custodia (el colaborador
 *   sigue teniendo el artículo hasta que ambas firmas concreten la devolución).
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
     * wizard: una fila por unidad identificada + una fila por renglón de
     * cantidad con saldo pendiente.
     *
     * @return list<array{tipo: string, tipo_etiqueta: string, activo: string, talla: string|null, cantidad: int, referencia: string|null}>
     */
    public function pendientes(Colaborador $colaborador): array
    {
        $unidades = UnidadActivo::query()
            ->where('colaborador_id', $colaborador->getKey())
            ->where('estado', EstadoUnidadActivo::Asignada)
            ->with('activo:id,nombre')
            ->orderBy('codigo')
            ->get()
            ->map(fn (UnidadActivo $u): array => [
                'tipo' => 'unidad',
                'tipo_etiqueta' => 'Unidad identificada',
                'activo' => $u->activo->nombre,
                'talla' => null,
                'cantidad' => 1,
                'referencia' => $u->codigo,
            ])
            ->all();

        $cantidades = array_map(fn (array $fila): array => [
            'tipo' => 'cantidad',
            'tipo_etiqueta' => 'Artículo por cantidad',
            'activo' => $fila['activo'],
            'talla' => $fila['talla'],
            'cantidad' => $fila['pendiente'],
            'referencia' => $fila['folio'],
        ], $this->cantidadesPendientes($colaborador));

        return array_merge($unidades, $cantidades);
    }

    /**
     * Renglones de cantidad con saldo pendiente de devolución. Mismo cálculo
     * que `RegistrarDevolucion::procesarLineaCantidad()`: entregado menos la
     * suma de lo ya devuelto en devoluciones CONFIRMADAS.
     *
     * @return list<array{activo: string, talla: string|null, pendiente: int, folio: string|null}>
     */
    private function cantidadesPendientes(Colaborador $colaborador): array
    {
        /** @var Collection<int, DetalleEntrega> $detalles */
        $detalles = DetalleEntrega::query()
            ->whereNull('unidad_activo_id')
            ->whereHas('entrega', fn ($q) => $q
                ->where('colaborador_id', $colaborador->getKey())
                ->whereIn('estado', ['firmada', 'corregida']))
            ->with('entrega:id,folio')
            ->get();

        if ($detalles->isEmpty()) {
            return [];
        }

        $devueltoPorDetalle = DetalleDevolucion::query()
            ->whereIn('detalle_entrega_id', $detalles->pluck('id'))
            ->whereHas('devolucion', fn ($q) => $q->where('estado', EstadoDevolucion::Confirmada))
            ->selectRaw('detalle_entrega_id, SUM(cantidad) as total')
            ->groupBy('detalle_entrega_id')
            ->pluck('total', 'detalle_entrega_id');

        $filas = [];

        foreach ($detalles as $detalle) {
            $pendiente = (int) $detalle->cantidad - (int) ($devueltoPorDetalle[$detalle->id] ?? 0);

            if ($pendiente <= 0) {
                continue;
            }

            $filas[] = [
                'activo' => $detalle->activo_nombre_snapshot,
                'talla' => $detalle->talla_valor_snapshot,
                'pendiente' => $pendiente,
                'folio' => $detalle->entrega?->folio,
            ];
        }

        return $filas;
    }
}
