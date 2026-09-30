<?php

namespace App\Acciones;

use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoDevolucion;
use App\Enums\EstadoEntrega;
use App\Enums\EstadoUnidadActivo;
use App\Enums\FinalidadCustodia;
use App\Enums\TipoControlActivo;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\Activo;
use App\Models\Colaborador;
use App\Models\Conjunto;
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
     * ¿Esta operación puede tomar de la bolsa de uso personal / sin
     * clasificar? Sólo con `entregas.redistribuir-propios` o dentro de la
     * revisión de un cambio de servicio (lo decide el llamador, nunca el
     * formulario).
     */
    private bool $incluirPersonales = false;

    /**
     * Cada renglón puede indicar de qué BOLSA de la custodia sale (`bolsa`:
     * `redistribucion` por defecto, o `personal`) y con qué FINALIDAD lo
     * recibe el destinatario (`finalidad`): nunca se hereda a ciegas — la
     * laptop que un supervisor recibió "para repartir" suele llegar al
     * destinatario como su "uso personal".
     *
     * @param  array<int, array{activo_id: int|string, talla_id?: int|string|null, cantidad: int|string, bolsa?: string|null, finalidad?: string|null}>  $activos
     * @param  array<int, array{unidad_activo_id: int|string, finalidad?: string|null}>  $unidades
     * @param  array<string, array{ruta: string, nombre_original: string, mime: string, extension: string, peso_bytes: int, hash_sha256: string, origen: string}>  $evidencias  claves "activo:{i}" / "unidad:{i}"
     * @param  array<int, array{conjunto_id: int|string, cantidad: int|string, finalidad?: string|null}>  $conjuntos  conjuntos COMPLETOS recibidos como tal y todavía bajo custodia
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
        array $conjuntos = [],
        bool $incluirPersonales = false,
    ): EntregaUniforme {
        $this->incluirPersonales = $incluirPersonales;

        if ($custodioId === $colaboradorId) {
            throw new ExcepcionDeNegocioSimple('No puedes entregarte a ti mismo activos de tu propia custodia.');
        }

        $lineasCantidad = array_filter($activos, fn (array $fila): bool => (int) $fila['cantidad'] > 0);

        $conjuntos = array_filter($conjuntos, fn (array $fila): bool => (int) $fila['cantidad'] > 0);

        if ($lineasCantidad === [] && $unidades === [] && $conjuntos === []) {
            throw new ExcepcionDeNegocioSimple('Agrega al menos un activo, unidad o conjunto de tu custodia a la entrega.');
        }

        return DB::transaction(function () use ($custodioId, $colaboradorId, $encargadoId, $fechaEntrega, $lineasCantidad, $unidades, $conjuntos, $notas, $servicioId, $evidencias): EntregaUniforme {
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
                $bolsa = ($fila['bolsa'] ?? null) === ServicioCustodiaColaborador::BOLSA_PERSONAL
                    ? ServicioCustodiaColaborador::BOLSA_PERSONAL
                    : ServicioCustodiaColaborador::BOLSA_REDISTRIBUCION;
                $finalidadDestino = FinalidadCustodia::tryFrom((string) ($fila['finalidad'] ?? ''));
                $creados = $this->redistribuirCantidad($entrega, $custodio, (int) $fila['activo_id'], $tallaId, (int) $fila['cantidad'], $bolsa, $finalidadDestino);

                if (isset($evidencias["activo:{$i}"])) {
                    $this->evidenciasSvc->adjuntar($creados[0], $evidencias["activo:{$i}"], $encargadoId);
                }

                $renglonesAuditoria[] = [
                    'activo' => $creados[0]->activo_nombre_snapshot,
                    'talla' => $creados[0]->talla_valor_snapshot,
                    'cantidad' => (int) $fila['cantidad'],
                    'desde' => $bolsa === ServicioCustodiaColaborador::BOLSA_PERSONAL ? 'Uso personal / sin clasificar' : 'Para redistribuir',
                    'finalidad_destinatario' => FinalidadCustodia::etiquetaDe($finalidadDestino),
                ];
            }

            $unidadesVistas = [];
            foreach ($unidades as $i => $fila) {
                $unidadId = (int) $fila['unidad_activo_id'];
                if (in_array($unidadId, $unidadesVistas, true)) {
                    continue;
                }
                $unidadesVistas[] = $unidadId;

                $finalidadDestino = FinalidadCustodia::tryFrom((string) ($fila['finalidad'] ?? ''));
                $bolsaUnidad = $this->custodia->bolsaDeUnidad(UnidadActivo::query()->findOrFail($unidadId));
                $detalle = $this->redistribuirUnidad($entrega, $custodio, $destinatario, $unidadId, null, $finalidadDestino);

                if (isset($evidencias["unidad:{$i}"])) {
                    $this->evidenciasSvc->adjuntar($detalle, $evidencias["unidad:{$i}"], $encargadoId);
                }

                $renglonesAuditoria[] = [
                    'activo' => $detalle->activo_nombre_snapshot,
                    'unidad' => $detalle->unidadActivo?->codigo,
                    'cantidad' => 1,
                    'desde' => $bolsaUnidad === ServicioCustodiaColaborador::BOLSA_PERSONAL ? 'Uso personal / sin clasificar' : 'Para redistribuir',
                    'finalidad_destinatario' => FinalidadCustodia::etiquetaDe($finalidadDestino),
                ];
            }

            foreach ($conjuntos as $fila) {
                foreach ($this->redistribuirConjunto($entrega, $custodio, $destinatario, (int) $fila['conjunto_id'], (int) $fila['cantidad'], $unidadesVistas, FinalidadCustodia::tryFrom((string) ($fila['finalidad'] ?? ''))) as $renglon) {
                    $renglonesAuditoria[] = $renglon;
                }
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
    private function redistribuirCantidad(EntregaUniforme $entrega, Colaborador $custodio, int $activoId, ?int $tallaId, int $cantidad, string $bolsa, ?FinalidadCustodia $finalidadDestino): array
    {
        if ($bolsa === ServicioCustodiaColaborador::BOLSA_PERSONAL && ! $this->incluirPersonales) {
            throw new ExcepcionDeNegocioSimple('No tienes permiso para reasignar activos de uso personal de tu custodia.');
        }

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
            ->when(
                $bolsa === ServicioCustodiaColaborador::BOLSA_REDISTRIBUCION,
                fn ($q) => $q->where('finalidad', FinalidadCustodia::Redistribucion),
                fn ($q) => $q->where(fn ($p) => $p->whereNull('finalidad')->orWhere('finalidad', FinalidadCustodia::UsoPersonal)),
            )
            ->whereHas('entrega', fn ($q) => $q
                ->where('colaborador_id', $custodio->getKey())
                ->whereIn('estado', [EstadoEntrega::Firmada, EstadoEntrega::Corregida]))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $tallaValor = $tallaId === null ? null : Talla::query()->whereKey($tallaId)->value('valor');
        $disponible = array_sum($this->custodia->pendientesPorDetalle($origenes));

        if ($cantidad > $disponible) {
            throw new ExcepcionDeNegocioSimple(sprintf(
                'Solicitaste %d de %s%s, pero en tu custodia sólo quedan %d.',
                $cantidad,
                $activo->nombre,
                $tallaValor !== null ? ' talla '.$tallaValor : '',
                $disponible,
            ));
        }

        return $this->tomarDeOrigenes($entrega, $activo, $origenes, $cantidad, null, $finalidadDestino);
    }

    /**
     * Toma `$cantidad` piezas de los renglones de origen YA BLOQUEADOS (más
     * antiguos primero), recalculando su pendiente bajo el lock, y crea los
     * renglones hijos (cada uno apunta a su `detalle_origen_id` y conserva la
     * variante que tenía la pieza). El llamador ya validó que alcanza.
     *
     * @param  Collection<int, DetalleEntrega>  $origenes
     * @return non-empty-list<DetalleEntrega>
     */
    private function tomarDeOrigenes(EntregaUniforme $entrega, Activo $activo, Collection $origenes, int $cantidad, ?Conjunto $conjunto = null, ?FinalidadCustodia $finalidadDestino = null): array
    {
        $pendientes = $this->custodia->pendientesPorDetalle($origenes);
        $restante = $cantidad;
        $creados = [];

        foreach ($origenes as $origen) {
            $tomar = min($restante, $pendientes[$origen->getKey()] ?? 0);
            if ($tomar <= 0) {
                continue;
            }

            $creados[] = $entrega->detalles()->create([
                'activo_id' => $activo->id,
                'talla_id' => $origen->talla_id,
                'cantidad' => $tomar,
                'detalle_origen_id' => $origen->getKey(),
                'finalidad' => $finalidadDestino,
                'activo_nombre_snapshot' => $activo->nombre,
                'talla_valor_snapshot' => $origen->talla_valor_snapshot,
                'conjunto_id' => $conjunto?->id,
                'conjunto_nombre_snapshot' => $conjunto?->nombre,
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
     * Redistribuye `$cantidad` conjuntos COMPLETOS que el custodio recibió
     * como tal: cada componente sale SÓLO de los renglones (o unidades) de su
     * custodia que llegaron con ese conjunto, y los renglones nuevos
     * conservan el conjunto como contexto. Si falta cualquier componente el
     * conjunto está incompleto y se rechaza — sus piezas sueltas pueden
     * redistribuirse como artículos o unidades individuales.
     *
     * @param  array<int, int>  $unidadesVistas
     * @return list<array{activo: string, talla?: string|null, unidad?: string|null, cantidad: int, conjunto: string}>
     */
    private function redistribuirConjunto(EntregaUniforme $entrega, Colaborador $custodio, Colaborador $destinatario, int $conjuntoId, int $cantidad, array &$unidadesVistas, ?FinalidadCustodia $finalidadDestino = null): array
    {
        $conjunto = Conjunto::query()
            ->where('empresa_id', $custodio->empresa_id)
            ->with('componentes.activo')
            ->findOr($conjuntoId, fn () => throw new ExcepcionDeNegocioSimple('Uno de los conjuntos seleccionados no pertenece a esta empresa.'));

        $incompleto = fn (string $activo): ExcepcionDeNegocioSimple => new ExcepcionDeNegocioSimple(
            "El conjunto «{$conjunto->nombre}» está incompleto en tu custodia (falta {$activo} para {$cantidad} conjunto(s)). Entrega sus piezas por separado."
        );

        $renglones = [];

        foreach ($conjunto->componentes as $componente) {
            $activo = $componente->activo;
            $necesarias = max((int) $componente->cantidad_requerida, 1) * $cantidad;

            if ($activo->tipo_control === TipoControlActivo::SeguimientoIndividual) {
                $candidatas = $this->custodia->unidadesRedistribuibles($custodio)
                    ->where('activo_id', $activo->id)
                    ->whereNotIn('id', $unidadesVistas)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->filter(fn (UnidadActivo $u): bool => $this->custodia->entregaActualDeUnidad($u)?->conjunto_id === $conjunto->id)
                    ->take($necesarias);

                if ($candidatas->count() < $necesarias) {
                    throw $incompleto($activo->nombre);
                }

                foreach ($candidatas as $unidad) {
                    $unidadesVistas[] = $unidad->id;
                    $detalle = $this->redistribuirUnidad($entrega, $custodio, $destinatario, $unidad->id, $conjunto, $finalidadDestino);
                    $renglones[] = ['activo' => $detalle->activo_nombre_snapshot, 'unidad' => $unidad->codigo, 'cantidad' => 1, 'conjunto' => $conjunto->nombre];
                }

                continue;
            }

            /** @var Collection<int, DetalleEntrega> $origenes */
            $origenes = DetalleEntrega::query()
                ->whereNull('unidad_activo_id')
                ->where('activo_id', $activo->id)
                ->where('conjunto_id', $conjunto->id)
                // Conjuntos: sólo la bolsa "para redistribuir".
                ->where('finalidad', FinalidadCustodia::Redistribucion)
                ->when($componente->talla_id !== null, fn ($q) => $q->where('talla_id', $componente->talla_id))
                ->whereHas('entrega', fn ($q) => $q
                    ->where('colaborador_id', $custodio->getKey())
                    ->whereIn('estado', [EstadoEntrega::Firmada, EstadoEntrega::Corregida]))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if (array_sum($this->custodia->pendientesPorDetalle($origenes)) < $necesarias) {
                throw $incompleto($activo->nombre);
            }

            foreach ($this->tomarDeOrigenes($entrega, $activo, $origenes, $necesarias, $conjunto, $finalidadDestino) as $creado) {
                $renglones[] = ['activo' => $creado->activo_nombre_snapshot, 'talla' => $creado->talla_valor_snapshot, 'cantidad' => (int) $creado->cantidad, 'conjunto' => $conjunto->nombre];
            }
        }

        return $renglones;
    }

    /**
     * Reasigna una unidad identificada del custodio al destinatario. La
     * unidad se bloquea y se revalida en el momento exacto de la
     * transacción: si ya no es del custodio (otra pestaña la entregó
     * primero), se rechaza.
     */
    private function redistribuirUnidad(EntregaUniforme $entrega, Colaborador $custodio, Colaborador $destinatario, int $unidadId, ?Conjunto $conjunto = null, ?FinalidadCustodia $finalidadDestino = null): DetalleEntrega
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

        if (! $this->incluirPersonales && $this->custodia->bolsaDeUnidad($unidad) !== ServicioCustodiaColaborador::BOLSA_REDISTRIBUCION) {
            throw new ExcepcionDeNegocioSimple("La unidad {$unidad->codigo} es de uso personal (o sin clasificar) y no tienes permiso para reasignarla.");
        }

        $origen = $this->custodia->entregaActualDeUnidad($unidad);
        $unidad->loadMissing('activo:id,nombre');

        $detalle = $entrega->detalles()->create([
            'activo_id' => $unidad->activo_id,
            'talla_id' => null,
            'unidad_activo_id' => $unidad->getKey(),
            'detalle_origen_id' => $origen?->getKey(),
            'finalidad' => $finalidadDestino,
            'cantidad' => 1,
            'activo_nombre_snapshot' => $unidad->activo->nombre,
            'talla_valor_snapshot' => null,
            'conjunto_id' => $conjunto?->id,
            'conjunto_nombre_snapshot' => $conjunto?->nombre,
        ]);

        // Sólo cambia QUIÉN la tiene. Sigue `Asignada` y conserva su almacén
        // de procedencia (al que volvería con una devolución); no hay
        // movimiento de inventario porque no sale nada de ningún almacén.
        $unidad->update(['colaborador_id' => $destinatario->getKey()]);

        return $detalle;
    }
}
