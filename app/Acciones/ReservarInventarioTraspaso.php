<?php

namespace App\Acciones;

use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoUnidadActivo;
use App\Enums\TipoReserva;
use App\Models\Activo;
use App\Models\Talla;
use App\Models\UnidadActivo;
use App\Servicios\ServicioInventario;
use App\Servicios\ServicioReservas;
use Illuminate\Support\Facades\DB;

/**
 * Apartado temporal (10 min, `Reserva::DURACION_MINUTOS`) del borrador de un
 * TRASPASO: mismo patrón que `ReservarInventarioEntrega` — recalcula TODA la
 * reserva del borrador de forma atómica, agrega la demanda por clave
 * `activo_id + talla_id`, bloquea saldos en orden estable y sólo aparta lo que
 * realmente cabe descontando lo que OTRAS reservas activas ya apartaron
 * (entregas y traspasos compiten por el mismo stock del almacén origen).
 *
 * Es sólo UX / concurrencia anticipada: `RegistrarTraspasoInventario`
 * conserva íntegras sus validaciones y candados sobre saldos y unidades al
 * confirmar, y consume la reserva dentro de su misma transacción.
 */
class ReservarInventarioTraspaso
{
    public function __construct(
        private readonly ServicioReservas $reservas,
        private readonly ServicioInventario $inventario,
    ) {}

    /**
     * @param  array<int, array{control?: string|null, activo_origen_id?: int|string|null, talla_id?: int|string|null, cantidad?: int|string|null, unidad_ids?: array<int, int|string>|null}>  $renglones  reglas laxas: un renglón a medio llenar se ignora
     * @return array{
     *     token: string, expira_en: string, ok: bool,
     *     lineas_cantidad: list<array{activo_id: int, talla_id: ?int, activo_nombre: ?string, talla_valor: ?string, disponible_efectivo: int, apartado_por_otros: int, solicitado: int, suficiente: bool}>,
     *     lineas_unidad: list<array{unidad_activo_id: int, ok: bool, motivo: ?string}>,
     * }
     */
    public function ejecutar(string $token, int $userId, int $empresaOrigenId, int $almacenOrigenId, array $renglones): array
    {
        return DB::transaction(function () use ($token, $userId, $empresaOrigenId, $almacenOrigenId, $renglones): array {
            $reserva = $this->reservas->obtenerOCrearCabecera($token, TipoReserva::Traspaso, $userId, $empresaOrigenId, $almacenOrigenId, null, null);

            /** @var array<string, array{activo_id: int, talla_id: ?int, cantidad: int}> $demanda */
            $demanda = [];
            $unidadIds = [];

            foreach ($renglones as $renglon) {
                $activoId = (int) ($renglon['activo_origen_id'] ?? 0);
                if ($activoId <= 0) {
                    continue;
                }

                if (($renglon['control'] ?? '') === 'individual') {
                    foreach ((array) ($renglon['unidad_ids'] ?? []) as $id) {
                        $unidadIds[(int) $id] = true;
                    }

                    continue;
                }

                $cantidad = (int) ($renglon['cantidad'] ?? 0);
                if ($cantidad <= 0) {
                    continue;
                }

                $tallaId = ($renglon['talla_id'] ?? null) !== null && $renglon['talla_id'] !== '' ? (int) $renglon['talla_id'] : null;
                $clave = $activoId.'-'.($tallaId ?? '0');
                $demanda[$clave] ??= ['activo_id' => $activoId, 'talla_id' => $tallaId, 'cantidad' => 0];
                $demanda[$clave]['cantidad'] += $cantidad;
            }

            // Cantidades: saldos bloqueados en orden ESTABLE (menos deadlocks).
            $claves = array_keys($demanda);
            sort($claves);
            $lineasCantidad = [];

            foreach ($claves as $clave) {
                $item = $demanda[$clave];

                // Sólo activos de la empresa origen: nunca se aparta stock ajeno.
                if (! Activo::query()->whereKey($item['activo_id'])->where('empresa_id', $empresaOrigenId)->exists()) {
                    continue;
                }

                $saldo = $this->inventario->lockearSaldo($empresaOrigenId, $almacenOrigenId, $item['activo_id'], $item['talla_id']);
                $deOtros = $this->reservas->demandaCantidadDeOtros(TipoReserva::Traspaso, $empresaOrigenId, $almacenOrigenId, $item['activo_id'], $item['talla_id'], $token);
                $disponible = max(0, (int) $saldo->cantidad - $deOtros);
                $aReservar = min($item['cantidad'], $disponible);

                if ($aReservar > 0) {
                    $reserva->renglones()->create([
                        'activo_id' => $item['activo_id'],
                        'talla_id' => $item['talla_id'],
                        'cantidad' => $aReservar,
                    ]);
                }

                $lineasCantidad[] = [
                    'activo_id' => $item['activo_id'],
                    'talla_id' => $item['talla_id'],
                    'activo_nombre' => Activo::query()->whereKey($item['activo_id'])->value('nombre'),
                    'talla_valor' => $item['talla_id'] !== null ? Talla::query()->whereKey($item['talla_id'])->value('valor') : null,
                    'disponible_efectivo' => $disponible,
                    // > 0 = la diferencia contra el saldo real la causan
                    // apartados de OTRAS operaciones (concurrencia), no
                    // falta de stock: el frontend lo explica así al usuario.
                    'apartado_por_otros' => min($deOtros, (int) $saldo->cantidad),
                    'solicitado' => $item['cantidad'],
                    'suficiente' => $item['cantidad'] <= $disponible,
                ];
            }

            // Unidades: cada una debe seguir en el almacén origen, operativa y
            // libre de cualquier otra reserva activa (entrega o traspaso).
            $ids = array_keys($unidadIds);
            sort($ids);
            $lineasUnidad = [];

            foreach ($ids as $unidadId) {
                $unidad = UnidadActivo::query()->whereKey($unidadId)->lockForUpdate()->first();

                if (! $unidad instanceof UnidadActivo
                    || $unidad->empresa_id !== $empresaOrigenId
                    || $unidad->almacen_id !== $almacenOrigenId
                    || $unidad->estado !== EstadoUnidadActivo::EnAlmacen
                    || $unidad->condicion !== CondicionUnidadActivo::Funcionando) {
                    $lineasUnidad[] = ['unidad_activo_id' => $unidadId, 'ok' => false, 'motivo' => 'Esa unidad ya no está disponible en el almacén origen.'];

                    continue;
                }

                if ($this->reservas->unidadApartadaPorOtro($unidadId, $token)) {
                    $lineasUnidad[] = ['unidad_activo_id' => $unidadId, 'ok' => false, 'motivo' => "La unidad {$unidad->codigo} acaba de ser apartada por otra operación."];

                    continue;
                }

                $reserva->renglones()->create(['unidad_activo_id' => $unidadId, 'activo_id' => $unidad->activo_id]);
                $lineasUnidad[] = ['unidad_activo_id' => $unidadId, 'ok' => true, 'motivo' => null];
            }

            $ok = ! in_array(false, array_column($lineasCantidad, 'suficiente'), true)
                && ! in_array(false, array_column($lineasUnidad, 'ok'), true);

            return [
                'token' => $reserva->token,
                'expira_en' => $reserva->expira_en->toIso8601String(),
                'ok' => $ok,
                'lineas_cantidad' => $lineasCantidad,
                'lineas_unidad' => $lineasUnidad,
            ];
        });
    }
}
