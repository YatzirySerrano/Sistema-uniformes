<?php

namespace App\Servicios;

use App\Enums\EstadoDevolucion;
use App\Enums\EstadoEntrega;
use App\Enums\EstadoUnidadActivo;
use App\Models\CambioServicioColaborador;
use App\Models\CambioServicioRenglon;
use App\Models\Colaborador;
use App\Models\DetalleDevolucion;
use App\Models\DetalleEntrega;
use App\Models\IncidenciaCustodia;
use App\Models\UnidadActivo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Evalúa la revisión de custodia de un cambio de servicio contra la
 * REALIDAD: cada decisión (mantener / devolver / redistribuir) se da por
 * resuelta sólo si las operaciones reales ya ocurrieron — devoluciones
 * CONFIRMADAS (firmadas) y redistribuciones firmadas hechas después de
 * iniciar la revisión (`corte_*`). Nunca guarda "resuelto": lo deriva cada
 * vez, así que no hay segunda fuente de verdad y un snapshot viejo nunca
 * basta para completar el cambio.
 *
 * Estados por renglón:
 * - `sin_decision`: falta decidir el reparto completo.
 * - `pendiente`: la decisión es coherente pero falta registrar/firmar la
 *   devolución o la redistribución.
 * - `resuelto`: la realidad coincide exactamente con lo decidido.
 * - `no_coincide`: la custodia cambió de otra forma (se devolvió o
 *   redistribuyó más de lo decidido, a otra persona…): hay que ajustar la
 *   decisión.
 */
class ServicioCambioServicio
{
    public function __construct(private readonly ServicioCustodiaColaborador $custodia) {}

    /**
     * Renglones a revisar a partir de la custodia ACTUAL del colaborador
     * (misma fuente que el panel de custodia): uno por renglón de entrega por
     * cantidad con saldo y uno por unidad identificada asignada.
     *
     * @param  array<int, int>  $excluirDetalles
     * @param  array<int, int>  $excluirUnidades
     * @return list<array{detalle_entrega_id: int|null, unidad_activo_id: int|null, activo_nombre_snapshot: string, talla_valor_snapshot: string|null, unidad_codigo_snapshot: string|null, cantidad_revisada: int}>
     */
    public function renglonesDeCustodia(Colaborador $colaborador, array $excluirDetalles = [], array $excluirUnidades = []): array
    {
        $renglones = [];

        foreach ($this->custodia->pendientes($colaborador) as $fila) {
            if ($fila['tipo'] === 'unidad') {
                if (in_array($fila['unidad_activo_id'], $excluirUnidades, true)) {
                    continue;
                }

                $renglones[] = [
                    'detalle_entrega_id' => null,
                    'unidad_activo_id' => $fila['unidad_activo_id'],
                    'activo_nombre_snapshot' => $fila['activo'],
                    'talla_valor_snapshot' => null,
                    'unidad_codigo_snapshot' => $fila['referencia'],
                    'cantidad_revisada' => 1,
                ];

                continue;
            }

            if (in_array($fila['detalle_entrega_id'], $excluirDetalles, true)) {
                continue;
            }

            $renglones[] = [
                'detalle_entrega_id' => $fila['detalle_entrega_id'],
                'unidad_activo_id' => null,
                'activo_nombre_snapshot' => $fila['activo'],
                'talla_valor_snapshot' => $fila['talla'],
                'unidad_codigo_snapshot' => null,
                'cantidad_revisada' => $fila['cantidad'],
            ];
        }

        return $renglones;
    }

    /**
     * Últimos ids existentes: todo lo que se registre DESPUÉS pertenece al
     * proceso de revisión.
     *
     * @return array{corte_detalle_entrega_id: int, corte_detalle_devolucion_id: int, corte_incidencia_id: int}
     */
    public function cortesActuales(): array
    {
        return [
            'corte_detalle_entrega_id' => (int) DB::table('detalles_entrega')->max('id'),
            'corte_detalle_devolucion_id' => (int) DB::table('detalles_devolucion')->max('id'),
            'corte_incidencia_id' => (int) DB::table('incidencias_custodia')->max('id'),
        ];
    }

    /**
     * @return array{
     *     completable: bool,
     *     renglones: list<array{id: int, estado: string, explicacion: string, faltante_devolver: int, faltante_redistribuir: int, devolucion_pendiente_firma: bool, entrega_id: int|null, operaciones: list<array{tipo: string, folio: string, id: int, detalle: string}>}>,
     *     nuevos: list<array{activo: string, talla: string|null, referencia: string|null, cantidad: int}>
     * }
     */
    public function evaluar(CambioServicioColaborador $cambio): array
    {
        $cambio->loadMissing(['renglones.destinatario:id,nombre_completo', 'renglones.detalleEntrega', 'renglones.unidadActivo']);
        $colaboradorId = $cambio->colaborador_id;

        $renglonesCantidad = $cambio->renglones->filter(fn (CambioServicioRenglon $r): bool => ! $r->esUnidad());
        $renglonesUnidad = $cambio->renglones->filter(fn (CambioServicioRenglon $r): bool => $r->esUnidad());

        $datosCantidad = $this->datosCantidad($cambio, $renglonesCantidad);
        $datosUnidad = $this->datosUnidad($cambio, $renglonesUnidad);

        $resultado = [];

        foreach ($cambio->renglones as $renglon) {
            $resultado[] = $renglon->esUnidad()
                ? $this->evaluarUnidad($renglon, $colaboradorId, $datosUnidad)
                : $this->evaluarCantidad($renglon, $datosCantidad);
        }

        $nuevos = $this->custodiaNoRevisada($cambio);

        $completable = $resultado !== []
            && $nuevos === []
            && collect($resultado)->every(fn (array $r): bool => $r['estado'] === 'resuelto');

        return ['completable' => $completable, 'renglones' => $resultado, 'nuevos' => $nuevos];
    }

    /**
     * Resumen legible de lo decidido y de las operaciones reales que lo
     * resolvieron, para la auditoría del cambio.
     *
     * @param  array{renglones: list<array{id: int, operaciones: list<array{tipo: string, folio: string, id: int, detalle: string}>}>}  $evaluacion
     * @return array{mantener: list<string>, devuelto: list<string>, redistribuido: list<string>}
     */
    public function resumenDecisiones(CambioServicioColaborador $cambio, array $evaluacion): array
    {
        $operacionesPorRenglon = collect($evaluacion['renglones'])->keyBy('id');
        $resumen = ['mantener' => [], 'devuelto' => [], 'redistribuido' => []];

        foreach ($cambio->renglones as $renglon) {
            $nombre = $renglon->activo_nombre_snapshot
                .($renglon->unidad_codigo_snapshot !== null ? ' '.$renglon->unidad_codigo_snapshot : '')
                .($renglon->talla_valor_snapshot !== null ? ' talla '.$renglon->talla_valor_snapshot : '');
            $conCantidad = fn (int $n): string => $renglon->esUnidad() ? $nombre : "{$nombre} x{$n}";

            if ($renglon->cantidad_mantener > 0) {
                $resumen['mantener'][] = $conCantidad($renglon->cantidad_mantener);
            }

            $operaciones = collect($operacionesPorRenglon->get($renglon->id)['operaciones'] ?? []);

            if ($renglon->cantidad_devolver > 0) {
                $folios = $operaciones->where('tipo', 'devolucion')->pluck('folio')->unique()->implode(', ');
                $resumen['devuelto'][] = $conCantidad($renglon->cantidad_devolver).($folios !== '' ? " ({$folios})" : '');
            }

            if ($renglon->cantidad_redistribuir > 0) {
                $folios = $operaciones->where('tipo', 'redistribucion')->pluck('folio')->unique()->implode(', ');
                $resumen['redistribuido'][] = $conCantidad($renglon->cantidad_redistribuir)
                    .' → '.($renglon->destinatario_id === null ? '—' : $renglon->destinatario->nombre_completo)
                    .($folios !== '' ? " ({$folios})" : '');
            }
        }

        return $resumen;
    }

    /**
     * @param  Collection<int, CambioServicioRenglon>  $renglones
     * @return array{pendiente: array<int, int>, devuelto: array<int, int>, devuelto_sin_firma: array<int, int>, incidencias: array<int, int>, redistribuido: array<int, array<int, int>>, operaciones: array<int, list<array{tipo: string, folio: string, id: int, detalle: string}>>}
     */
    private function datosCantidad(CambioServicioColaborador $cambio, Collection $renglones): array
    {
        $idsDetalle = $renglones->pluck('detalle_entrega_id')->filter()->values()->all();

        if ($idsDetalle === []) {
            return ['pendiente' => [], 'devuelto' => [], 'devuelto_sin_firma' => [], 'incidencias' => [], 'redistribuido' => [], 'operaciones' => []];
        }

        $detalles = DetalleEntrega::query()->whereIn('id', $idsDetalle)->get();
        $pendiente = $this->custodia->pendientesPorDetalle($detalles);

        $devoluciones = DetalleDevolucion::query()
            ->whereIn('detalle_entrega_id', $idsDetalle)
            ->where('id', '>', $cambio->corte_detalle_devolucion_id)
            ->with('devolucion:id,folio,estado')
            ->get();

        $devuelto = [];
        $devueltoSinFirma = [];
        $operaciones = [];

        foreach ($devoluciones as $dd) {
            $detalleId = (int) $dd->detalle_entrega_id;
            if ($dd->devolucion?->estado === EstadoDevolucion::Confirmada) {
                $devuelto[$detalleId] = ($devuelto[$detalleId] ?? 0) + (int) $dd->cantidad;
                $operaciones[$detalleId][] = ['tipo' => 'devolucion', 'folio' => (string) $dd->devolucion->folio, 'id' => (int) $dd->devolucion->id, 'detalle' => "Devueltas {$dd->cantidad}"];
            } elseif ($dd->devolucion?->estado === EstadoDevolucion::PendienteFirma) {
                $devueltoSinFirma[$detalleId] = ($devueltoSinFirma[$detalleId] ?? 0) + (int) $dd->cantidad;
            }
        }

        $hijos = DetalleEntrega::query()
            ->whereIn('detalle_origen_id', $idsDetalle)
            ->where('id', '>', $cambio->corte_detalle_entrega_id)
            ->whereHas('entrega', fn ($q) => $q->where('estado', '!=', EstadoEntrega::Anulada))
            ->with('entrega:id,folio,colaborador_id', 'entrega.colaborador:id,nombre_completo')
            ->get();

        $redistribuido = [];
        foreach ($hijos as $hijo) {
            $origenId = (int) $hijo->detalle_origen_id;
            $destinoId = (int) $hijo->entrega?->colaborador_id;
            $redistribuido[$origenId][$destinoId] = ($redistribuido[$origenId][$destinoId] ?? 0) + (int) $hijo->cantidad;
            $operaciones[$origenId][] = [
                'tipo' => 'redistribucion',
                'folio' => (string) $hijo->entrega?->folio,
                'id' => (int) $hijo->entrega_uniforme_id,
                'detalle' => "{$hijo->cantidad} a ".($hijo->entrega === null ? '—' : $hijo->entrega->colaborador->nombre_completo),
            ];
        }

        $incidencias = IncidenciaCustodia::query()
            ->whereIn('detalle_entrega_id', $idsDetalle)
            ->where('id', '>', $cambio->corte_incidencia_id)
            ->selectRaw('detalle_entrega_id, SUM(cantidad) as total')
            ->groupBy('detalle_entrega_id')
            ->pluck('total', 'detalle_entrega_id')
            ->map(fn ($t): int => (int) $t)
            ->all();

        return [
            'pendiente' => $pendiente,
            'devuelto' => $devuelto,
            'devuelto_sin_firma' => $devueltoSinFirma,
            'incidencias' => $incidencias,
            'redistribuido' => $redistribuido,
            'operaciones' => $operaciones,
        ];
    }

    /**
     * @param  array{pendiente: array<int, int>, devuelto: array<int, int>, devuelto_sin_firma: array<int, int>, incidencias: array<int, int>, redistribuido: array<int, array<int, int>>, operaciones: array<int, list<array{tipo: string, folio: string, id: int, detalle: string}>>}  $datos
     * @return array{id: int, estado: string, explicacion: string, faltante_devolver: int, faltante_redistribuir: int, devolucion_pendiente_firma: bool, entrega_id: int|null, operaciones: list<array{tipo: string, folio: string, id: int, detalle: string}>}
     */
    private function evaluarCantidad(CambioServicioRenglon $renglon, array $datos): array
    {
        $detalleId = (int) $renglon->detalle_entrega_id;
        $pendiente = $datos['pendiente'][$detalleId] ?? 0;
        $devuelto = $datos['devuelto'][$detalleId] ?? 0;
        $incidencias = $datos['incidencias'][$detalleId] ?? 0;
        $porDestino = $datos['redistribuido'][$detalleId] ?? [];
        $aDestinatario = $renglon->destinatario_id !== null ? ($porDestino[$renglon->destinatario_id] ?? 0) : 0;
        $aOtros = array_sum($porDestino) - $aDestinatario;

        $base = [
            'id' => $renglon->id,
            'faltante_devolver' => max($renglon->cantidad_devolver - $devuelto, 0),
            'faltante_redistribuir' => max($renglon->cantidad_redistribuir - $aDestinatario, 0),
            'devolucion_pendiente_firma' => ($datos['devuelto_sin_firma'][$detalleId] ?? 0) > 0,
            'entrega_id' => $renglon->detalleEntrega?->entrega_uniforme_id,
            'operaciones' => $datos['operaciones'][$detalleId] ?? [],
        ];

        if (! $renglon->estaDecidido()) {
            return [...$base, 'estado' => 'sin_decision', 'explicacion' => 'Falta decidir qué pasa con todas las piezas.'];
        }

        if ($devuelto > $renglon->cantidad_devolver || $aDestinatario > $renglon->cantidad_redistribuir || $aOtros > 0) {
            return [...$base, 'estado' => 'no_coincide', 'explicacion' => 'Se devolvió o redistribuyó distinto a lo decidido. Ajusta la decisión a lo que realmente ocurrió.'];
        }

        // La custodia actual (más lo reportado como robo/pérdida durante el
        // proceso) debe explicar exactamente lo que falta por mover.
        $esperado = $renglon->cantidad_mantener + $base['faltante_devolver'] + $base['faltante_redistribuir'];

        if ($pendiente + $incidencias !== $esperado) {
            return [...$base, 'estado' => 'no_coincide', 'explicacion' => 'La custodia de este renglón cambió desde la revisión. Ajusta la decisión.'];
        }

        if ($base['faltante_devolver'] === 0 && $base['faltante_redistribuir'] === 0) {
            return [...$base, 'estado' => 'resuelto', 'explicacion' => 'Resuelto.'];
        }

        return [...$base, 'estado' => 'pendiente', 'explicacion' => $this->explicacionPendiente($base)];
    }

    /**
     * @param  Collection<int, CambioServicioRenglon>  $renglones
     * @return array{devuelta: array<int, array{tipo: string, folio: string, id: int, detalle: string}>, sin_firma: array<int, bool>, redistribuida: array<int, array{colaborador_id: int, op: array{tipo: string, folio: string, id: int, detalle: string}}>}
     */
    private function datosUnidad(CambioServicioColaborador $cambio, Collection $renglones): array
    {
        $idsUnidad = $renglones->pluck('unidad_activo_id')->filter()->values()->all();
        $datos = ['devuelta' => [], 'sin_firma' => [], 'redistribuida' => []];

        if ($idsUnidad === []) {
            return $datos;
        }

        $devoluciones = DetalleDevolucion::query()
            ->whereIn('unidad_activo_id', $idsUnidad)
            ->where('id', '>', $cambio->corte_detalle_devolucion_id)
            ->whereHas('devolucion', fn ($q) => $q->where('colaborador_id', $cambio->colaborador_id))
            ->with('devolucion:id,folio,estado')
            ->get();

        foreach ($devoluciones as $dd) {
            $unidadId = (int) $dd->unidad_activo_id;
            if ($dd->devolucion?->estado === EstadoDevolucion::Confirmada) {
                $datos['devuelta'][$unidadId] = ['tipo' => 'devolucion', 'folio' => (string) $dd->devolucion->folio, 'id' => (int) $dd->devolucion->id, 'detalle' => 'Devuelta'];
            } elseif ($dd->devolucion?->estado === EstadoDevolucion::PendienteFirma) {
                $datos['sin_firma'][$unidadId] = true;
            }
        }

        $redistribuciones = DetalleEntrega::query()
            ->whereIn('unidad_activo_id', $idsUnidad)
            ->where('id', '>', $cambio->corte_detalle_entrega_id)
            ->whereHas('entrega', fn ($q) => $q->where('colaborador_origen_id', $cambio->colaborador_id)->where('estado', '!=', EstadoEntrega::Anulada))
            ->with('entrega:id,folio,colaborador_id', 'entrega.colaborador:id,nombre_completo')
            ->orderBy('id')
            ->get();

        foreach ($redistribuciones as $d) {
            $datos['redistribuida'][(int) $d->unidad_activo_id] = [
                'colaborador_id' => (int) $d->entrega?->colaborador_id,
                'op' => [
                    'tipo' => 'redistribucion',
                    'folio' => (string) $d->entrega?->folio,
                    'id' => (int) $d->entrega_uniforme_id,
                    'detalle' => 'A '.($d->entrega === null ? '—' : $d->entrega->colaborador->nombre_completo),
                ],
            ];
        }

        return $datos;
    }

    /**
     * @param  array{devuelta: array<int, array{tipo: string, folio: string, id: int, detalle: string}>, sin_firma: array<int, bool>, redistribuida: array<int, array{colaborador_id: int, op: array{tipo: string, folio: string, id: int, detalle: string}}>}  $datos
     * @return array{id: int, estado: string, explicacion: string, faltante_devolver: int, faltante_redistribuir: int, devolucion_pendiente_firma: bool, entrega_id: int|null, operaciones: list<array{tipo: string, folio: string, id: int, detalle: string}>}
     */
    private function evaluarUnidad(CambioServicioRenglon $renglon, int $colaboradorId, array $datos): array
    {
        $unidad = $renglon->unidadActivo;
        $unidadId = (int) $renglon->unidad_activo_id;
        $sigueConElColaborador = $unidad instanceof UnidadActivo
            && $unidad->colaborador_id === $colaboradorId
            && $unidad->estado === EstadoUnidadActivo::Asignada;

        $operaciones = array_values(array_filter([
            $datos['devuelta'][$unidadId] ?? null,
            ($datos['redistribuida'][$unidadId] ?? null)['op'] ?? null,
        ]));

        $base = [
            'id' => $renglon->id,
            'faltante_devolver' => 0,
            'faltante_redistribuir' => 0,
            'devolucion_pendiente_firma' => $datos['sin_firma'][$unidadId] ?? false,
            'entrega_id' => $unidad instanceof UnidadActivo ? $this->custodia->entregaActualDeUnidad($unidad)?->entrega_uniforme_id : null,
            'operaciones' => $operaciones,
        ];

        if (! $renglon->estaDecidido()) {
            return [...$base, 'estado' => 'sin_decision', 'explicacion' => 'Falta decidir qué pasa con esta unidad.'];
        }

        if ($renglon->cantidad_mantener === 1) {
            return $sigueConElColaborador
                ? [...$base, 'estado' => 'resuelto', 'explicacion' => 'Continúa con el colaborador.']
                : [...$base, 'estado' => 'no_coincide', 'explicacion' => 'La unidad ya no está bajo su custodia. Ajusta la decisión.'];
        }

        if ($renglon->cantidad_devolver === 1) {
            if ($sigueConElColaborador) {
                return [...$base, 'estado' => 'pendiente', 'faltante_devolver' => 1, 'explicacion' => $this->explicacionPendiente([...$base, 'faltante_devolver' => 1])];
            }

            return isset($datos['devuelta'][$unidadId])
                ? [...$base, 'estado' => 'resuelto', 'explicacion' => 'Devuelta al almacén.']
                : [...$base, 'estado' => 'no_coincide', 'explicacion' => 'La unidad salió de su custodia sin una devolución. Ajusta la decisión.'];
        }

        if ($sigueConElColaborador) {
            return [...$base, 'estado' => 'pendiente', 'faltante_redistribuir' => 1, 'explicacion' => $this->explicacionPendiente([...$base, 'faltante_redistribuir' => 1])];
        }

        $redistribuida = $datos['redistribuida'][$unidadId] ?? null;

        return $redistribuida !== null
            && $redistribuida['colaborador_id'] === $renglon->destinatario_id
            && $unidad?->colaborador_id === $renglon->destinatario_id
            ? [...$base, 'estado' => 'resuelto', 'explicacion' => 'Redistribuida.']
            : [...$base, 'estado' => 'no_coincide', 'explicacion' => 'La unidad no quedó con el colaborador elegido. Ajusta la decisión.'];
    }

    /**
     * @param  array{faltante_devolver: int, faltante_redistribuir: int, devolucion_pendiente_firma: bool}  $base
     */
    private function explicacionPendiente(array $base): string
    {
        $partes = [];

        if ($base['faltante_devolver'] > 0) {
            $partes[] = $base['devolucion_pendiente_firma']
                ? 'la devolución está pendiente de firma'
                : "falta registrar la devolución ({$base['faltante_devolver']})";
        }

        if ($base['faltante_redistribuir'] > 0) {
            $partes[] = "falta registrar la redistribución ({$base['faltante_redistribuir']})";
        }

        return ucfirst(implode(' y ', $partes)).'.';
    }

    /**
     * Custodia actual que no forma parte de la revisión (p. ej. le
     * entregaron algo después de iniciarla): obliga a revisarla antes de
     * completar.
     *
     * @return list<array{activo: string, talla: string|null, referencia: string|null, cantidad: int}>
     */
    private function custodiaNoRevisada(CambioServicioColaborador $cambio): array
    {
        $colaborador = Colaborador::query()->findOrFail($cambio->colaborador_id);

        return array_map(fn (array $r): array => [
            'activo' => $r['activo_nombre_snapshot'],
            'talla' => $r['talla_valor_snapshot'],
            'referencia' => $r['unidad_codigo_snapshot'],
            'cantidad' => $r['cantidad_revisada'],
        ], $this->renglonesDeCustodia(
            $colaborador,
            $cambio->renglones->pluck('detalle_entrega_id')->filter()->map(fn ($id): int => (int) $id)->values()->all(),
            $cambio->renglones->pluck('unidad_activo_id')->filter()->map(fn ($id): int => (int) $id)->values()->all(),
        ));
    }
}
