<?php

namespace App\Acciones;

use App\Enums\CondicionDevolucion;
use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoDevolucion;
use App\Enums\EstadoUnidadActivo;
use App\Enums\TipoReserva;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\DetalleDevolucion;
use App\Models\DetalleEntrega;
use App\Models\Devolucion;
use App\Models\Empresa;
use App\Models\EntregaUniforme;
use App\Models\UnidadActivo;
use App\Models\User;
use App\Policies\DevolucionPolicy;
use App\Servicios\ResolverAlmacenOperativo;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioCustodiaColaborador;
use App\Servicios\ServicioEvidencias;
use App\Servicios\ServicioFolios;
use App\Servicios\ServicioReservas;
use Illuminate\Support\Facades\DB;

/**
 * Registra la SOLICITUD de devolución (`estado=pendiente_firma`), SIEMPRE
 * originada desde una entrega concreta. Cada renglón referencia el renglón
 * real de esa entrega (`DetalleEntrega`) — nunca activo/talla sueltos — así se
 * deriva la cantidad ya devuelta y se rechaza devolver más de lo pendiente.
 *
 * IMPORTANTE: esta acción NO mueve inventario ni cambia el estado de ninguna
 * `UnidadActivo` — sólo dejaría el activo "disponible" antes de que la
 * devolución quede jurídicamente concretada (ambas firmas + consentimiento).
 * El reingreso real (saldo o unidad) se aplica en `ConfirmarAcuseDevolucion`,
 * dentro de la misma transacción protegida que crea el acuse. Perdido/Robado
 * nunca pasa por aquí (son incidencias, `App\Acciones\MarcarUnidadIncidencia`).
 */
class RegistrarDevolucion
{
    public function __construct(
        private readonly ServicioFolios $folios,
        private readonly ServicioAuditoria $auditoria,
        private readonly ResolverAlmacenOperativo $resolverAlmacen,
        private readonly ServicioEvidencias $evidenciasSvc,
        private readonly ServicioReservas $reservas,
        private readonly ServicioCustodiaColaborador $custodia,
    ) {}

    /**
     * @param  array<int, array{detalle_entrega_id: int|string, cantidad: int|string, condicion?: string|null, condiciones?: array<int, array{condicion: string, cantidad: int|string}>|null}>  $activos  `condiciones` = mismo renglón repartido entre varias condiciones (suma exacta = `cantidad`)
     * @param  array<int, array{detalle_entrega_id: int|string, condicion: string}>  $unidades
     * @param  array<string, array{ruta: string, nombre_original: string, mime: string, extension: string, peso_bytes: int, hash_sha256: string, origen: string}>  $evidencias  claves "activo:{i}" / "unidad:{i}"
     */
    public function ejecutar(
        int $entregaId,
        int $almacenId,
        string $fecha,
        array $activos,
        array $unidades,
        ?int $registradaPor,
        ?string $motivo = null,
        ?string $notas = null,
        array $evidencias = [],
        ?string $reservaToken = null,
    ): Devolucion {
        return DB::transaction(fn (): Devolucion => $this->crearYRegistrar(
            $entregaId, $almacenId, $fecha, $activos, $unidades, $registradaPor, $motivo, $notas, $evidencias, $reservaToken,
        ));
    }

    /**
     * Núcleo transaccional reutilizable: NO abre `DB::transaction` — asume que
     * ya hay una activa (la abre el llamador: `ejecutar()` para el camino
     * diferido, `RegistrarDevolucionFirmada` para el wizard). Crea la
     * `Devolucion` (`pendiente_firma`), sus `DetalleDevolucion`, adjunta las
     * `Evidencia` y audita. NO toca inventario ni `UnidadActivo` (eso es
     * `ConfirmarAcuseDevolucion`).
     *
     * @param  array<int, array{detalle_entrega_id: int|string, cantidad: int|string, condicion?: string|null, condiciones?: array<int, array{condicion: string, cantidad: int|string}>|null}>  $activos  `condiciones` = mismo renglón repartido entre varias condiciones (suma exacta = `cantidad`)
     * @param  array<int, array{detalle_entrega_id: int|string, condicion: string}>  $unidades
     * @param  array<string, array{ruta: string, nombre_original: string, mime: string, extension: string, peso_bytes: int, hash_sha256: string, origen: string}>  $evidencias
     */
    public function crearYRegistrar(
        int $entregaId,
        int $almacenId,
        string $fecha,
        array $activos,
        array $unidades,
        ?int $registradaPor,
        ?string $motivo = null,
        ?string $notas = null,
        array $evidencias = [],
        ?string $reservaToken = null,
    ): Devolucion {
        $entrega = EntregaUniforme::query()->findOr($entregaId, fn () => throw new ExcepcionDeNegocioSimple('La entrega indicada no existe.'));

        $almacen = $this->resolverAlmacen->paraEmpresa(Empresa::query()->findOrFail($entrega->empresa_id), $almacenId);

        if ($activos === [] && $unidades === []) {
            throw new ExcepcionDeNegocioSimple('Agrega al menos un renglón a devolver.');
        }

        // Nadie se recibe a sí mismo lo de USO PERSONAL / sin clasificar de
        // su propia custodia (`DevolucionPolicy::recibirRenglon`, por cada
        // renglón/unidad seleccionado). Se revalida aquí, antes de crear
        // nada, aunque la pantalla y el apartado ya lo hayan impedido: una
        // petición manipulada no deja devolución, movimientos ni reserva
        // consumida.
        $receptor = $registradaPor !== null ? User::query()->find($registradaPor) : null;
        if ($receptor !== null) {
            $idsSeleccionados = array_map(
                fn (array $fila): int => (int) $fila['detalle_entrega_id'],
                [...$activos, ...$unidades],
            );
            $rechazo = DevolucionPolicy::motivoRechazoRenglones(
                $receptor,
                $entrega->detalles()->whereIn('id', $idsSeleccionados)->get(),
            );
            if ($rechazo !== null) {
                throw new ExcepcionDeNegocioSimple($rechazo);
            }
        }

        // Capa previa de UX/concurrencia: si viene token, se valida que la
        // reserva exista, sea de este usuario y siga vigente. Nunca reemplaza
        // los candados/recuentos de abajo (`procesarLineaCantidad`/
        // `procesarLineaUnidad`), que son la autoridad real.
        $reserva = $reservaToken !== null && $registradaPor !== null
            ? $this->reservas->bloquearActivaPorToken($reservaToken, $registradaPor, TipoReserva::Devolucion)
            : null;

        $devolucion = Devolucion::query()->create([
            'folio' => $this->folios->siguiente(ServicioFolios::DEVOLUCION),
            'empresa_id' => $entrega->empresa_id,
            'sucursal_id' => $entrega->sucursal_id,
            'almacen_id' => $almacen->getKey(),
            'colaborador_id' => $entrega->colaborador_id,
            'entrega_uniforme_id' => $entrega->getKey(),
            'registrada_por' => $registradaPor,
            'fecha' => $fecha,
            'motivo' => $motivo,
            'notas' => $notas,
            'estado' => EstadoDevolucion::PendienteFirma,
        ]);

        foreach ($activos as $i => $item) {
            $detalles = $this->procesarLineaCantidad($devolucion, $entrega, $item);
            // Repartido por condición: la foto sigue siendo del renglón
            // devuelto y queda en su primera parte.
            if ($detalles !== [] && isset($evidencias["activo:{$i}"])) {
                $this->evidenciasSvc->adjuntar($detalles[0], $evidencias["activo:{$i}"], $registradaPor);
            }
        }

        foreach ($unidades as $i => $item) {
            $detalle = $this->procesarLineaUnidad($devolucion, $entrega, $item);
            if (isset($evidencias["unidad:{$i}"])) {
                $this->evidenciasSvc->adjuntar($detalle, $evidencias["unidad:{$i}"], $registradaPor);
            }
        }

        $reserva?->update(['consumida_en' => now()]);

        $this->auditoria->registrar('devoluciones', 'crear', [
            'tipo_entidad' => Devolucion::class,
            'entidad_id' => $devolucion->getKey(),
            'empresa_id' => $entrega->empresa_id,
            'sucursal_id' => $entrega->sucursal_id,
            'descripcion' => 'Devolución '.$devolucion->folio.' registrada para la entrega '.$entrega->folio.'.',
        ]);

        return $devolucion->load('detalles');
    }

    /**
     * Un renglón por cantidad, con UNA condición (flujo de siempre) o
     * repartido entre varias (`condiciones`): una fila `DetalleDevolucion`
     * por condición, todas del mismo `DetalleEntrega` y de esta misma
     * devolución. El tope contra lo pendiente se aplica UNA vez sobre el
     * total, y cada parte conserva su propio efecto al confirmar (sólo
     * "Reutilizable" reingresa a stock).
     *
     * @param  array{detalle_entrega_id: int|string, cantidad: int|string, condicion?: string|null, condiciones?: array<int, array{condicion: string, cantidad: int|string}>|null}  $item
     * @return list<DetalleDevolucion>
     */
    private function procesarLineaCantidad(Devolucion $devolucion, EntregaUniforme $entrega, array $item): array
    {
        $detalleOriginal = DetalleEntrega::query()
            ->where('entrega_uniforme_id', $entrega->getKey())
            ->whereNull('unidad_activo_id')
            ->lockForUpdate()
            ->findOr((int) $item['detalle_entrega_id'], fn () => throw new ExcepcionDeNegocioSimple('Ese renglón no pertenece a esta entrega.'));

        $cantidad = (int) $item['cantidad'];

        if ($cantidad <= 0) {
            return [];
        }

        // OJO: este tope cuenta TODA devolución ya solicitada de este renglón
        // sin importar su estado (confirmada o todavía pendiente_firma) —
        // a propósito distinto de `ServicioCustodiaColaborador::pendienteDeDetalle()`
        // (que sólo cuenta confirmadas, para decidir qué mostrar/seleccionar).
        // Aquí es el tope de "cuánto queda por SOLICITAR": impide exceder lo
        // entregado aunque una solicitud previa siga sin firmar — el mismo
        // lock de fila de arriba serializa intentos concurrentes sobre este
        // renglón (recalculado DESPUÉS de tomar el lock).
        $yaSolicitado = (int) DetalleDevolucion::query()
            ->where('detalle_entrega_id', $detalleOriginal->getKey())
            ->sum('cantidad');
        // Lo que el colaborador ya REDISTRIBUYÓ desde este renglón ahora es
        // custodia de otro: no puede devolverlo él (el mismo lock de fila
        // serializa esta devolución con una redistribución concurrente).
        $redistribuido = $this->custodia->redistribuidoPorDetalle([$detalleOriginal->getKey()])[$detalleOriginal->getKey()] ?? 0;
        $pendiente = (int) $detalleOriginal->cantidad - $yaSolicitado - $redistribuido;

        if ($cantidad > $pendiente) {
            throw new ExcepcionDeNegocioSimple(sprintf(
                'Intentas devolver %d de %s, pero sólo quedan %d pendientes de esta entrega.',
                $cantidad,
                $detalleOriginal->activo_nombre_snapshot,
                max($pendiente, 0),
            ));
        }

        // El reingreso real al saldo se aplica al confirmar el acuse
        // (`ConfirmarAcuseDevolucion`); aquí sólo se deja constancia de qué
        // parte reingresará cuando eso ocurra.
        $detalles = [];
        foreach ($this->partesPorCondicion($item, $cantidad, $detalleOriginal->activo_nombre_snapshot) as [$condicion, $parte]) {
            $detalles[] = $devolucion->detalles()->create([
                'detalle_entrega_id' => $detalleOriginal->getKey(),
                'activo_id' => $detalleOriginal->activo_id,
                'talla_id' => $detalleOriginal->talla_id,
                'cantidad' => $parte,
                'condicion' => $condicion,
                'reingresa_inventario' => $condicion->reingresaInventario(),
            ]);
        }

        return $detalles;
    }

    /**
     * Normaliza el renglón a pares [condición, cantidad]. Sin desglose: una
     * sola parte con la condición única (compatibilidad total con el flujo
     * anterior). Con desglose: cada condición válida para devolución, sin
     * repetir, enteros ≥ 0 (los ceros se omiten) y suma EXACTA al total —
     * revalidado aquí aunque el Form Request ya lo haya hecho.
     *
     * @param  array{cantidad: int|string, condicion?: string|null, condiciones?: array<int, array{condicion: string, cantidad: int|string}>|null}  $item
     * @return list<array{0: CondicionDevolucion, 1: int}>
     */
    private function partesPorCondicion(array $item, int $cantidad, ?string $nombreActivo): array
    {
        $desglose = $item['condiciones'] ?? null;

        if (! is_array($desglose) || $desglose === []) {
            $condicion = CondicionDevolucion::tryFrom((string) ($item['condicion'] ?? ''));
            if ($condicion === null || $condicion === CondicionDevolucion::RoboExtravio) {
                throw new ExcepcionDeNegocioSimple('Elige la condición en que se recibe «'.$nombreActivo.'».');
            }

            return [[$condicion, $cantidad]];
        }

        $partes = [];
        $vistas = [];
        $suma = 0;
        foreach ($desglose as $fila) {
            $condicion = CondicionDevolucion::tryFrom($fila['condicion']);
            $parte = filter_var($fila['cantidad'], FILTER_VALIDATE_INT);

            if ($condicion === null || $condicion === CondicionDevolucion::RoboExtravio || in_array($condicion, $vistas, true)) {
                throw new ExcepcionDeNegocioSimple('La distribución por condición de «'.$nombreActivo.'» tiene una condición inválida o repetida.');
            }
            if ($parte === false || $parte < 0) {
                throw new ExcepcionDeNegocioSimple('La distribución por condición de «'.$nombreActivo.'» sólo admite cantidades enteras no negativas.');
            }

            $vistas[] = $condicion;
            $suma += $parte;
            if ($parte > 0) {
                $partes[] = [$condicion, $parte];
            }
        }

        if ($suma !== $cantidad) {
            throw new ExcepcionDeNegocioSimple(sprintf(
                'La distribución por condición de «%s» suma %d, pero la cantidad a devolver es %d.',
                $nombreActivo,
                $suma,
                $cantidad,
            ));
        }

        return $partes;
    }

    /**
     * @param  array{detalle_entrega_id: int|string, condicion: string}  $item
     */
    private function procesarLineaUnidad(Devolucion $devolucion, EntregaUniforme $entrega, array $item): DetalleDevolucion
    {
        $detalleOriginal = DetalleEntrega::query()
            ->where('entrega_uniforme_id', $entrega->getKey())
            ->whereNotNull('unidad_activo_id')
            ->findOr((int) $item['detalle_entrega_id'], fn () => throw new ExcepcionDeNegocioSimple('Esa unidad no pertenece a esta entrega.'));

        $unidad = UnidadActivo::query()->whereKey($detalleOriginal->unidad_activo_id)->lockForUpdate()->firstOrFail();

        if ($unidad->estado !== EstadoUnidadActivo::Asignada) {
            throw new ExcepcionDeNegocioSimple('Esta unidad no está asignada actualmente; no se puede devolver.');
        }

        // Redistribuida después de esta entrega: ya es custodia de otro
        // colaborador y sólo él (desde su propia entrega) puede devolverla.
        if ($unidad->colaborador_id !== $entrega->colaborador_id) {
            throw new ExcepcionDeNegocioSimple("La unidad {$unidad->codigo} ya no está bajo la custodia de este colaborador (fue redistribuida); devuélvela desde la entrega de quien la tiene actualmente.");
        }

        $condicion = CondicionUnidadActivo::from($item['condicion']);

        if ($condicion->esIncidencia()) {
            throw new ExcepcionDeNegocioSimple('Pérdida o robo no se registra como devolución. Usa "Reportar incidencia" desde la unidad.');
        }

        if ($this->unidadTieneDevolucionPendiente($unidad->getKey())) {
            throw new ExcepcionDeNegocioSimple('Esta unidad ya tiene una devolución pendiente de firma.');
        }

        // La unidad permanece "Asignada" (no disponible para reasignación)
        // hasta que la devolución se confirme con ambas firmas: el cambio de
        // estado/almacén real ocurre en `ConfirmarAcuseDevolucion`.
        return $devolucion->detalles()->create([
            'detalle_entrega_id' => $detalleOriginal->getKey(),
            'activo_id' => $detalleOriginal->activo_id,
            'talla_id' => null,
            'unidad_activo_id' => $unidad->getKey(),
            'cantidad' => 1,
            'condicion_unidad' => $condicion,
            'reingresa_inventario' => false,
        ]);
    }

    private function unidadTieneDevolucionPendiente(int $unidadId): bool
    {
        return DetalleDevolucion::query()
            ->where('unidad_activo_id', $unidadId)
            ->whereHas('devolucion', fn ($q) => $q->where('estado', EstadoDevolucion::PendienteFirma))
            ->exists();
    }
}
