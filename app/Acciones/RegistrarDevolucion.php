<?php

namespace App\Acciones;

use App\Enums\CondicionDevolucion;
use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoDevolucion;
use App\Enums\EstadoUnidadActivo;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\DetalleDevolucion;
use App\Models\DetalleEntrega;
use App\Models\Devolucion;
use App\Models\Empresa;
use App\Models\EntregaUniforme;
use App\Models\UnidadActivo;
use App\Servicios\ResolverAlmacenOperativo;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioEvidencias;
use App\Servicios\ServicioFolios;
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
    ) {}

    /**
     * @param  array<int, array{detalle_entrega_id: int|string, cantidad: int|string, condicion: string}>  $activos
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
    ): Devolucion {
        $entrega = EntregaUniforme::query()->findOr($entregaId, fn () => throw new ExcepcionDeNegocioSimple('La entrega indicada no existe.'));

        $almacen = $this->resolverAlmacen->paraEmpresa(Empresa::query()->findOrFail($entrega->empresa_id), $almacenId);

        if ($activos === [] && $unidades === []) {
            throw new ExcepcionDeNegocioSimple('Agrega al menos un renglón a devolver.');
        }

        return DB::transaction(function () use ($entrega, $almacen, $activos, $unidades, $fecha, $registradaPor, $motivo, $notas, $evidencias): Devolucion {
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
                $detalle = $this->procesarLineaCantidad($devolucion, $entrega, $item);
                if ($detalle !== null && isset($evidencias["activo:{$i}"])) {
                    $this->evidenciasSvc->adjuntar($detalle, $evidencias["activo:{$i}"], $registradaPor);
                }
            }

            foreach ($unidades as $i => $item) {
                $detalle = $this->procesarLineaUnidad($devolucion, $entrega, $item);
                if (isset($evidencias["unidad:{$i}"])) {
                    $this->evidenciasSvc->adjuntar($detalle, $evidencias["unidad:{$i}"], $registradaPor);
                }
            }

            $this->auditoria->registrar('devoluciones', 'crear', [
                'tipo_entidad' => Devolucion::class,
                'entidad_id' => $devolucion->getKey(),
                'empresa_id' => $entrega->empresa_id,
                'sucursal_id' => $entrega->sucursal_id,
                'descripcion' => 'Devolución '.$devolucion->folio.' registrada para la entrega '.$entrega->folio.'; pendiente de firma para concretarse.',
            ]);

            return $devolucion->load('detalles');
        });
    }

    /**
     * @param  array{detalle_entrega_id: int|string, cantidad: int|string, condicion: string}  $item
     */
    private function procesarLineaCantidad(Devolucion $devolucion, EntregaUniforme $entrega, array $item): ?DetalleDevolucion
    {
        $detalleOriginal = DetalleEntrega::query()
            ->where('entrega_uniforme_id', $entrega->getKey())
            ->whereNull('unidad_activo_id')
            ->lockForUpdate()
            ->findOr((int) $item['detalle_entrega_id'], fn () => throw new ExcepcionDeNegocioSimple('Ese renglón no pertenece a esta entrega.'));

        $cantidad = (int) $item['cantidad'];

        if ($cantidad <= 0) {
            return null;
        }

        $yaDevuelto = (int) DetalleDevolucion::query()
            ->where('detalle_entrega_id', $detalleOriginal->getKey())
            ->sum('cantidad');
        $pendiente = (int) $detalleOriginal->cantidad - $yaDevuelto;

        if ($cantidad > $pendiente) {
            throw new ExcepcionDeNegocioSimple(sprintf(
                'Intentas devolver %d de %s, pero sólo quedan %d pendientes de esta entrega.',
                $cantidad,
                $detalleOriginal->activo_nombre_snapshot,
                max($pendiente, 0),
            ));
        }

        $condicion = CondicionDevolucion::from($item['condicion']);

        // El reingreso real al saldo se aplica al confirmar el acuse
        // (`ConfirmarAcuseDevolucion`); aquí sólo se deja constancia de que
        // esta línea reingresará cuando eso ocurra.
        return $devolucion->detalles()->create([
            'detalle_entrega_id' => $detalleOriginal->getKey(),
            'activo_id' => $detalleOriginal->activo_id,
            'talla_id' => $detalleOriginal->talla_id,
            'cantidad' => $cantidad,
            'condicion' => $condicion,
            'reingresa_inventario' => $condicion->reingresaInventario(),
        ]);
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
