<?php

namespace App\Acciones;

use App\Enums\CondicionUnidadActivo;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\CambioServicioColaborador;
use App\Models\CambioServicioRenglon;
use App\Models\Colaborador;
use App\Servicios\ServicioCambioServicio;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Guarda (o ajusta) las decisiones de la revisión de custodia: por renglón,
 * cuánto se MANTIENE con el colaborador, cuánto se DEVUELVE al almacén y
 * cuánto se REDISTRIBUYE a otro colaborador. Nunca ejecuta devoluciones ni
 * redistribuciones: sólo registra el plan que luego se cumple con los flujos
 * reales (firmados) y se verifica en `ServicioCambioServicio::evaluar()`.
 *
 * El Form Request ya validó forma y destinatario; aquí se revalida la regla
 * de dominio bajo lock (el reparto nunca supera lo revisado).
 */
class GuardarDecisionesCambioServicio
{
    public function __construct(private readonly ServicioCambioServicio $cambios) {}

    /**
     * @param  array<int|string, array{mantener: int|string, devolver: int|string, redistribuir: int|string, destinatario_id?: int|string|null}>  $decisiones  indexado por id de renglón
     */
    public function ejecutar(CambioServicioColaborador $cambio, array $decisiones): CambioServicioColaborador
    {
        return DB::transaction(function () use ($cambio, $decisiones): CambioServicioColaborador {
            $cambio = $this->bloquearPendiente($cambio);

            /** @var Collection<int, CambioServicioRenglon> $renglones */
            $renglones = $cambio->renglones()->with('unidadActivo')->lockForUpdate()->get()->keyBy('id');

            foreach ($decisiones as $renglonId => $decision) {
                $renglon = $renglones->get((int) $renglonId);

                if ($renglon === null) {
                    throw new ExcepcionDeNegocioSimple('Uno de los bienes no pertenece a esta revisión.');
                }

                $mantener = (int) $decision['mantener'];
                $devolver = (int) $decision['devolver'];
                $redistribuir = (int) $decision['redistribuir'];

                if ($mantener + $devolver + $redistribuir !== $renglon->cantidad_revisada) {
                    throw new ExcepcionDeNegocioSimple("La decisión de «{$renglon->activo_nombre_snapshot}» debe repartir exactamente {$renglon->cantidad_revisada}.");
                }

                // Una unidad perdida, robada o dañada no puede devolverse ni
                // redistribuirse por estos flujos: se mantiene con su
                // responsable hasta resolverse por su propio flujo.
                if ($renglon->esUnidad() && $mantener === 0 && $renglon->unidadActivo?->condicion !== CondicionUnidadActivo::Funcionando) {
                    throw new ExcepcionDeNegocioSimple("La unidad {$renglon->unidad_codigo_snapshot} está reportada como {$renglon->unidadActivo?->condicion->etiqueta()}: sólo puede mantenerse con el colaborador hasta resolver su incidencia o reparación.");
                }

                $renglon->update([
                    'cantidad_mantener' => $mantener,
                    'cantidad_devolver' => $devolver,
                    'cantidad_redistribuir' => $redistribuir,
                    'destinatario_id' => $redistribuir > 0 ? ((int) ($decision['destinatario_id'] ?? 0) ?: null) : null,
                ]);
            }

            return $cambio;
        });
    }

    /**
     * Incorpora a la revisión lo que llegó a la custodia del colaborador
     * DESPUÉS de iniciarla (sin decisión todavía).
     */
    public function incorporarCustodiaNueva(CambioServicioColaborador $cambio): int
    {
        return DB::transaction(function () use ($cambio): int {
            $cambio = $this->bloquearPendiente($cambio);
            $cambio->load('renglones');

            $nuevos = $this->cambios->renglonesDeCustodia(
                Colaborador::query()->findOrFail($cambio->colaborador_id),
                $cambio->renglones->pluck('detalle_entrega_id')->filter()->map(fn ($id): int => (int) $id)->values()->all(),
                $cambio->renglones->pluck('unidad_activo_id')->filter()->map(fn ($id): int => (int) $id)->values()->all(),
            );

            foreach ($nuevos as $renglon) {
                $cambio->renglones()->create($renglon);
            }

            return count($nuevos);
        });
    }

    private function bloquearPendiente(CambioServicioColaborador $cambio): CambioServicioColaborador
    {
        /** @var CambioServicioColaborador $bloqueado */
        $bloqueado = CambioServicioColaborador::query()->whereKey($cambio->getKey())->lockForUpdate()->firstOrFail();

        if (! $bloqueado->estaPendiente()) {
            throw new ExcepcionDeNegocioSimple('Esta revisión de cambio de servicio ya fue completada o cancelada.');
        }

        return $bloqueado;
    }
}
