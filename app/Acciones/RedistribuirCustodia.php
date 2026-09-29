<?php

namespace App\Acciones;

use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoDevolucion;
use App\Enums\EstadoEntrega;
use App\Enums\EstadoUnidadActivo;
use App\Enums\TipoControlActivo;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\Activo;
use App\Models\Colaborador;
use App\Models\DetalleDevolucion;
use App\Models\DetalleEntrega;
use App\Models\EntregaUniforme;
use App\Models\Talla;
use App\Models\UnidadActivo;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioCustodiaColaborador;
use App\Servicios\ServicioEvidencias;
use App\Servicios\ServicioFolios;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Entrega de tipo REDISTRIBUCIÓN: un colaborador custodio (p. ej. un
 * Supervisor que recibió bienes del almacén) entrega a otro colaborador
 * bienes que YA están bajo su custodia. Es un evento propio (una
 * `EntregaUniforme` nueva con `colaborador_origen_id`), nunca una edición de
 * la entrega original: así se conserva la cadena Almacén → custodio →
 * destinatario.
 *
 * Diferencia con `CrearEntregaUniforme` (salida de almacén): aquí NO se toca
 * inventario. Los bienes ya salieron del almacén cuando se entregaron al
 * custodio; lo único que cambia es QUIÉN los tiene:
 *
 * - Por cantidad: cada renglón nuevo apunta (`detalle_origen_id`) al renglón
 *   de la custodia del que salen las piezas (FIFO por antigüedad). La custodia
 *   del origen se deriva restando esos hijos (`ServicioCustodiaColaborador`),
 *   la del destinatario suma su propio renglón. Sin saldos paralelos.
 * - Unidades: `unidades_activo.colaborador_id` pasa al destinatario (sigue
 *   `Asignada`, mismo almacén de procedencia, sin movimiento de inventario).
 *
 * Todo se valida DENTRO de la transacción, con candados sobre ambos
 * colaboradores, los renglones de origen y cada unidad: dos pestañas del
 * mismo custodio intentando entregar la misma pieza se serializan y la
 * segunda ve la custodia ya reducida.
 */
class RedistribuirCustodia
{
    public function __construct(
        private readonly ServicioCustodiaColaborador $custodia,
        private readonly ServicioFolios $folios,
        private readonly ServicioAuditoria $auditoria,
        private readonly ServicioEvidencias $evidenciasSvc,
    ) {}

    /**
     * @param  array<int, array{activo_id: int|string, talla_id?: int|string|null, cantidad: int|string}>  $activos
     * @param  array<int, array{unidad_activo_id: int|string}>  $unidades
     * @param  array<string, array{ruta: string, nombre_original: string, mime: string, extension: string, peso_bytes: int, hash_sha256: string, origen: string}>  $evidencias  claves "activo:{i}" / "unidad:{i}"
     */
    public function ejecutar(
        int $custodioId,
        int $colaboradorId,
        int $encargadoId,
        string $fechaEntrega,
        array $activos,
        array $unidades,
        ?string $notas = null,
        ?int $servicioId = null,
        array $evidencias = [],
    ): EntregaUniforme {
        if ($custodioId === $colaboradorId) {
            throw new ExcepcionDeNegocioSimple('No puedes entregarte a ti mismo activos de tu propia custodia.');
        }

        $lineasCantidad = array_filter($activos, fn (array $fila): bool => (int) $fila['cantidad'] > 0);

        if ($lineasCantidad === [] && $unidades === []) {
            throw new ExcepcionDeNegocioSimple('Agrega al menos un activo o unidad de tu custodia a la entrega.');
        }

        return DB::transaction(function () use ($custodioId, $colaboradorId, $encargadoId, $fechaEntrega, $lineasCantidad, $unidades, $notas, $servicioId, $evidencias): EntregaUniforme {
            // Candados en orden estable (id ascendente) para que dos
            // redistribuciones cruzadas (A→B y B→A) no se bloqueen entre sí.
            $bloqueados = Colaborador::query()
                ->whereKey([$custodioId, $colaboradorId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $custodio = $bloqueados->get($custodioId);
            $destinatario = $bloqueados->get($colaboradorId);

            if ($custodio === null || ! $custodio->activo) {
                throw new ExcepcionDeNegocioSimple('No tienes una custodia activa desde la cual entregar.');
            }

            if ($destinatario === null || ! $destinatario->activo) {
                throw new ExcepcionDeNegocioSimple('El colaborador está inactivo y no puede recibir entregas.');
            }

            if ($destinatario->empresa_id !== $custodio->empresa_id) {
                throw new ExcepcionDeNegocioSimple('Sólo puedes redistribuir tu custodia a colaboradores de la misma empresa.');
            }

            $entrega = EntregaUniforme::query()->create([
                'folio' => $this->folios->siguiente(ServicioFolios::ENTREGA),
                'empresa_id' => $destinatario->empresa_id,
                'sucursal_id' => $destinatario->sucursal_id,
                // Sin almacén: esta entrega no saca nada de ningún almacén.
                'almacen_id' => null,
                'colaborador_origen_id' => $custodio->getKey(),
                'servicio_id' => $servicioId,
                'colaborador_id' => $destinatario->getKey(),
                'encargado_id' => $encargadoId,
                'estado' => EstadoEntrega::PendienteFirma,
                'fecha_entrega' => $fechaEntrega,
                'notas' => $notas,
            ]);

            $renglonesAuditoria = [];

            foreach ($lineasCantidad as $i => $fila) {
                $tallaId = ($fila['talla_id'] ?? null) !== null && $fila['talla_id'] !== '' ? (int) $fila['talla_id'] : null;
                $creados = $this->redistribuirCantidad($entrega, $custodio, (int) $fila['activo_id'], $tallaId, (int) $fila['cantidad']);

                if (isset($evidencias["activo:{$i}"])) {
                    $this->evidenciasSvc->adjuntar($creados[0], $evidencias["activo:{$i}"], $encargadoId);
                }

                $renglonesAuditoria[] = [
                    'activo' => $creados[0]->activo_nombre_snapshot,
                    'talla' => $creados[0]->talla_valor_snapshot,
                    'cantidad' => (int) $fila['cantidad'],
                ];
            }

            $unidadesVistas = [];
            foreach ($unidades as $i => $fila) {
                $unidadId = (int) $fila['unidad_activo_id'];
                if (in_array($unidadId, $unidadesVistas, true)) {
                    continue;
                }
                $unidadesVistas[] = $unidadId;

                $detalle = $this->redistribuirUnidad($entrega, $custodio, $destinatario, $unidadId);

                if (isset($evidencias["unidad:{$i}"])) {
                    $this->evidenciasSvc->adjuntar($detalle, $evidencias["unidad:{$i}"], $encargadoId);
                }

                $renglonesAuditoria[] = [
                    'activo' => $detalle->activo_nombre_snapshot,
                    'unidad' => $detalle->unidadActivo?->codigo,
                    'cantidad' => 1,
                ];
            }

            $destinatario->loadMissing(['empresa:id,nombre_comercial', 'servicioActual:id,nombre']);

            $this->auditoria->registrar('entregas', 'redistribuir', [
                'tipo_entidad' => EntregaUniforme::class,
                'entidad_id' => $entrega->getKey(),
                'empresa_id' => $entrega->empresa_id,
                'sucursal_id' => $entrega->sucursal_id,
                'descripcion' => sprintf(
                    'Redistribución %s: de la custodia de %s a %s.',
                    $entrega->folio,
                    $custodio->nombre_completo,
                    $destinatario->nombre_completo,
                ),
                // Etiquetas humanas (no `*_id`): `DescripcionAuditoria` oculta
                // los `*_id` sin resolverlos.
                'valores_nuevos' => [
                    'folio' => $entrega->folio,
                    'tipo' => 'Redistribución de custodia',
                    'origen' => $custodio->nombre_completo.' ('.$custodio->numero_empleado.')',
                    'destinatario' => $destinatario->nombre_completo.' ('.$destinatario->numero_empleado.')',
                    'empresa' => $destinatario->empresa?->nombre_comercial,
                    'servicio' => $destinatario->servicio_actual_id === null ? 'Sin servicio' : $destinatario->servicioActual->nombre,
                    'renglones' => $renglonesAuditoria,
                ],
                'motivo' => $notas,
            ]);

            return $entrega->load(['detalles', 'colaborador', 'colaboradorOrigen', 'sucursal', 'encargado']);
        });
    }

    /**
     * Toma `$cantidad` piezas de los renglones de la custodia del origen
     * (más antiguos primero) y crea los renglones hijos correspondientes.
     * Recalcula el pendiente DESPUÉS de bloquear los renglones de origen.
     *
     * @return non-empty-list<DetalleEntrega>
     */
    private function redistribuirCantidad(EntregaUniforme $entrega, Colaborador $custodio, int $activoId, ?int $tallaId, int $cantidad): array
    {
        $activo = Activo::query()
            ->where('empresa_id', $custodio->empresa_id)
            ->where('tipo_control', TipoControlActivo::Cantidad)
            ->where('activo', true)
            ->findOr($activoId, fn () => throw new ExcepcionDeNegocioSimple('Uno de los activos seleccionados no pertenece a esta empresa o ya no está disponible.'));

        /** @var Collection<int, DetalleEntrega> $origenes */
        $origenes = DetalleEntrega::query()
            ->whereNull('unidad_activo_id')
            ->where('activo_id', $activo->id)
            ->when($tallaId === null, fn ($q) => $q->whereNull('talla_id'), fn ($q) => $q->where('talla_id', $tallaId))
            ->whereHas('entrega', fn ($q) => $q
                ->where('colaborador_id', $custodio->getKey())
                ->whereIn('estado', [EstadoEntrega::Firmada, EstadoEntrega::Corregida]))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $pendientes = $this->custodia->pendientesPorDetalle($origenes);
        $disponible = array_sum($pendientes);

        $tallaValor = $tallaId === null ? null : Talla::query()->whereKey($tallaId)->value('valor');

        if ($cantidad > $disponible) {
            throw new ExcepcionDeNegocioSimple(sprintf(
                'Solicitaste %d de %s%s, pero en tu custodia sólo quedan %d.',
                $cantidad,
                $activo->nombre,
                $tallaValor !== null ? ' talla '.$tallaValor : '',
                $disponible,
            ));
        }

        $restante = $cantidad;
        $creados = [];

        foreach ($origenes as $origen) {
            $tomar = min($restante, $pendientes[$origen->getKey()] ?? 0);
            if ($tomar <= 0) {
                continue;
            }

            $creados[] = $entrega->detalles()->create([
                'activo_id' => $activo->id,
                'talla_id' => $tallaId,
                'cantidad' => $tomar,
                'detalle_origen_id' => $origen->getKey(),
                'activo_nombre_snapshot' => $activo->nombre,
                'talla_valor_snapshot' => $tallaValor ?? $origen->talla_valor_snapshot,
            ]);

            $restante -= $tomar;
            if ($restante === 0) {
                break;
            }
        }

        /** @var non-empty-list<DetalleEntrega> $creados */
        return $creados;
    }

    /**
     * Reasigna una unidad identificada del custodio al destinatario. La
     * unidad se bloquea y se revalida en el momento exacto de la
     * transacción: si ya no es del custodio (otra pestaña la entregó
     * primero), se rechaza.
     */
    private function redistribuirUnidad(EntregaUniforme $entrega, Colaborador $custodio, Colaborador $destinatario, int $unidadId): DetalleEntrega
    {
        $unidad = UnidadActivo::query()->whereKey($unidadId)->lockForUpdate()->first();

        if (! $unidad instanceof UnidadActivo
            || $unidad->empresa_id !== $custodio->empresa_id
            || $unidad->colaborador_id !== $custodio->getKey()
            || $unidad->estado !== EstadoUnidadActivo::Asignada
        ) {
            throw new ExcepcionDeNegocioSimple('Una de las unidades seleccionadas ya no está bajo tu custodia.');
        }

        if ($unidad->condicion !== CondicionUnidadActivo::Funcionando) {
            throw new ExcepcionDeNegocioSimple("La unidad {$unidad->codigo} está reportada como {$unidad->condicion->etiqueta()}; no puede redistribuirse.");
        }

        $tieneDevolucionPendiente = DetalleDevolucion::query()
            ->where('unidad_activo_id', $unidad->getKey())
            ->whereHas('devolucion', fn ($q) => $q->where('estado', EstadoDevolucion::PendienteFirma))
            ->exists();

        if ($tieneDevolucionPendiente) {
            throw new ExcepcionDeNegocioSimple("La unidad {$unidad->codigo} tiene una devolución pendiente de firma; no puede redistribuirse.");
        }

        $origen = $this->custodia->entregaActualDeUnidad($unidad);
        $unidad->loadMissing('activo:id,nombre');

        $detalle = $entrega->detalles()->create([
            'activo_id' => $unidad->activo_id,
            'talla_id' => null,
            'unidad_activo_id' => $unidad->getKey(),
            'detalle_origen_id' => $origen?->getKey(),
            'cantidad' => 1,
            'activo_nombre_snapshot' => $unidad->activo->nombre,
            'talla_valor_snapshot' => null,
        ]);

        // Sólo cambia QUIÉN la tiene. Sigue `Asignada` y conserva su almacén
        // de procedencia (al que volvería con una devolución); no hay
        // movimiento de inventario porque no sale nada de ningún almacén.
        $unidad->update(['colaborador_id' => $destinatario->getKey()]);

        return $detalle;
    }
}
