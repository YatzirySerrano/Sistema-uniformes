<?php

namespace App\Acciones;

use App\Enums\TipoIncidenciaCustodia;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\Colaborador;
use App\Models\DetalleEntrega;
use App\Models\IncidenciaCustodia;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioCustodiaColaborador;
use Illuminate\Support\Facades\DB;

/**
 * Reporta el robo o la pérdida de N piezas de un activo POR CANTIDAD que un
 * colaborador tiene bajo custodia (entregadas, no devueltas). Las piezas ya
 * habían salido del almacén desde la propia entrega, así que esta acción
 * NUNCA toca `saldos_inventario` ni genera `MovimientoInventario` — sólo dej
 * a un evento persistente que reduce el PENDIENTE DE DEVOLUCIÓN del renglón
 * de origen (`ServicioCustodiaColaborador::pendientesPorDetalle()`), nunca
 * dos veces la misma pieza entre esta fuente y las devoluciones confirmadas.
 * Equivalente, para control por cantidad, de `MarcarUnidadIncidencia` (que
 * cumple el mismo papel para `UnidadActivo`).
 */
class RegistrarIncidenciaCustodia
{
    public function __construct(
        private readonly ServicioCustodiaColaborador $custodia,
        private readonly ServicioAuditoria $auditoria,
    ) {}

    public function ejecutar(
        Colaborador $colaborador,
        int $detalleEntregaId,
        TipoIncidenciaCustodia $tipo,
        int $cantidad,
        string $motivo,
        ?string $observacion,
        ?int $realizadoPor,
    ): IncidenciaCustodia {
        if (trim($motivo) === '') {
            throw new ExcepcionDeNegocioSimple('El motivo es obligatorio.');
        }
        if ($cantidad <= 0) {
            throw new ExcepcionDeNegocioSimple('La cantidad debe ser mayor a cero.');
        }

        return DB::transaction(function () use ($colaborador, $detalleEntregaId, $tipo, $cantidad, $motivo, $observacion, $realizadoPor): IncidenciaCustodia {
            // PRIMERA consulta de la transacción: bajo `lockForUpdate()`,
            // serializa contra cualquier otro reporte concurrente sobre el
            // MISMO renglón — las sumas de `pendienteDeDetalle()` que siguen
            // (lecturas normales) quedan garantizadas de ver el commit de una
            // transacción que acabara de liberar este mismo candado.
            $detalle = DetalleEntrega::query()
                ->whereKey($detalleEntregaId)
                ->with('entrega')
                ->lockForUpdate()
                ->first();

            if ($detalle === null || $detalle->unidad_activo_id !== null) {
                throw new ExcepcionDeNegocioSimple('Ese renglón no corresponde a un artículo por cantidad.');
            }

            $entrega = $detalle->entrega;

            if ($entrega === null || (int) $entrega->colaborador_id !== $colaborador->getKey()) {
                throw new ExcepcionDeNegocioSimple('Ese renglón no corresponde a una entrega de este colaborador.');
            }

            if (! in_array($entrega->estado->value, ['firmada', 'corregida'], true)) {
                throw new ExcepcionDeNegocioSimple('La entrega de origen no está firmada.');
            }

            $pendiente = $this->custodia->pendienteDeDetalle($detalle);

            if ($cantidad > $pendiente) {
                throw new ExcepcionDeNegocioSimple("Sólo hay {$pendiente} pieza(s) pendiente(s) de devolución en ese renglón.");
            }

            $registro = IncidenciaCustodia::query()->create([
                'empresa_id' => $entrega->empresa_id,
                'colaborador_id' => $colaborador->getKey(),
                'entrega_uniforme_id' => $entrega->getKey(),
                'detalle_entrega_id' => $detalle->getKey(),
                'activo_id' => $detalle->activo_id,
                'talla_id' => $detalle->talla_id,
                'activo_nombre_snapshot' => $detalle->activo_nombre_snapshot,
                'talla_valor_snapshot' => $detalle->talla_valor_snapshot,
                'tipo' => $tipo,
                'cantidad' => $cantidad,
                'motivo' => $motivo,
                'observacion' => $observacion,
                'registrado_por' => $realizadoPor,
            ]);

            $nombreActivo = $detalle->talla_valor_snapshot !== null
                ? "{$detalle->activo_nombre_snapshot} · {$detalle->talla_valor_snapshot}"
                : $detalle->activo_nombre_snapshot;

            $this->auditoria->registrar('custodia', 'incidencia_registrar', [
                'tipo_entidad' => IncidenciaCustodia::class,
                'entidad_id' => $registro->getKey(),
                'empresa_id' => $entrega->empresa_id,
                'motivo' => $motivo,
                'descripcion' => "{$cantidad} pieza(s) de {$nombreActivo} bajo custodia de {$colaborador->nombre_completo} reportadas como {$tipo->etiqueta()}. Motivo: {$motivo}.",
                'valores_anteriores' => ['tipo' => null],
                'valores_nuevos' => ['tipo' => $tipo->value, 'cantidad' => $cantidad],
            ]);

            return $registro;
        });
    }
}
