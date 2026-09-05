<?php

namespace App\Acciones;

use App\Enums\CondicionDevolucion;
use App\Enums\CondicionUnidadActivo;
use App\Enums\TipoMovimiento;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\DetalleDevolucion;
use App\Models\DetalleEntrega;
use App\Models\Devolucion;
use App\Models\Empresa;
use App\Models\EntregaUniforme;
use App\Models\UnidadActivo;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ResolverAlmacenOperativo;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioFolios;
use App\Servicios\ServicioInventario;
use App\Servicios\ServicioUnidadesActivo;
use Illuminate\Support\Facades\DB;

/**
 * Registra la devolución de activos, SIEMPRE originada desde una entrega
 * concreta. Cada renglón referencia el renglón real de esa entrega
 * (`DetalleEntrega`) — nunca activo/talla sueltos — así se deriva la
 * cantidad ya devuelta y se rechaza devolver más de lo pendiente. Las
 * unidades de seguimiento individual vuelven a almacén según la condición
 * resultante (`ServicioUnidadesActivo::devolver`); Perdido/Robado nunca pasa
 * por aquí (son incidencias, `App\Acciones\MarcarUnidadIncidencia`).
 */
class RegistrarDevolucion
{
    public function __construct(
        private readonly ServicioInventario $inventario,
        private readonly ServicioUnidadesActivo $unidadesActivo,
        private readonly ServicioFolios $folios,
        private readonly ServicioAuditoria $auditoria,
        private readonly ResolverAlmacenOperativo $resolverAlmacen,
    ) {}

    /**
     * @param  array<int, array{detalle_entrega_id: int|string, cantidad: int|string, condicion: string}>  $activos
     * @param  array<int, array{detalle_entrega_id: int|string, condicion: string}>  $unidades
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
    ): Devolucion {
        $entrega = EntregaUniforme::query()->findOr($entregaId, fn () => throw new ExcepcionDeNegocioSimple('La entrega indicada no existe.'));

        $almacen = $this->resolverAlmacen->paraEmpresa(Empresa::query()->findOrFail($entrega->empresa_id), $almacenId);

        if ($activos === [] && $unidades === []) {
            throw new ExcepcionDeNegocioSimple('Agrega al menos un renglón a devolver.');
        }

        return DB::transaction(function () use ($entrega, $almacen, $activos, $unidades, $fecha, $registradaPor, $motivo, $notas): Devolucion {
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
            ]);

            foreach ($activos as $item) {
                $this->procesarLineaCantidad($devolucion, $entrega, $almacen->getKey(), $registradaPor, $item);
            }

            foreach ($unidades as $item) {
                $this->procesarLineaUnidad($devolucion, $entrega, $almacen->getKey(), $registradaPor, $item);
            }

            $this->auditoria->registrar('devoluciones', 'crear', [
                'tipo_entidad' => Devolucion::class,
                'entidad_id' => $devolucion->getKey(),
                'empresa_id' => $entrega->empresa_id,
                'sucursal_id' => $entrega->sucursal_id,
                'descripcion' => 'Devolución '.$devolucion->folio.' registrada para la entrega '.$entrega->folio,
            ]);

            return $devolucion->load('detalles');
        });
    }

    /**
     * @param  array{detalle_entrega_id: int|string, cantidad: int|string, condicion: string}  $item
     */
    private function procesarLineaCantidad(Devolucion $devolucion, EntregaUniforme $entrega, int $almacenId, ?int $registradaPor, array $item): void
    {
        $detalleOriginal = DetalleEntrega::query()
            ->where('entrega_uniforme_id', $entrega->getKey())
            ->whereNull('unidad_activo_id')
            ->lockForUpdate()
            ->findOr((int) $item['detalle_entrega_id'], fn () => throw new ExcepcionDeNegocioSimple('Ese renglón no pertenece a esta entrega.'));

        $cantidad = (int) $item['cantidad'];

        if ($cantidad <= 0) {
            return;
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
        $reingresa = $condicion->reingresaInventario();

        $devolucion->detalles()->create([
            'detalle_entrega_id' => $detalleOriginal->getKey(),
            'activo_id' => $detalleOriginal->activo_id,
            'talla_id' => $detalleOriginal->talla_id,
            'cantidad' => $cantidad,
            'condicion' => $condicion,
            'reingresa_inventario' => $reingresa,
        ]);

        if ($reingresa) {
            $this->inventario->registrarMovimiento(new MovimientoInventarioDatos(
                empresaId: $entrega->empresa_id,
                almacenId: $almacenId,
                activoId: $detalleOriginal->activo_id,
                tallaId: $detalleOriginal->talla_id,
                tipo: TipoMovimiento::Devolucion,
                cantidad: $cantidad,
                realizadoPor: $registradaPor,
                referenciaTipo: Devolucion::class,
                referenciaId: $devolucion->getKey(),
                motivo: 'Devolución '.$devolucion->folio,
                sucursalId: $entrega->sucursal_id,
            ));
        }
    }

    /**
     * @param  array{detalle_entrega_id: int|string, condicion: string}  $item
     */
    private function procesarLineaUnidad(Devolucion $devolucion, EntregaUniforme $entrega, int $almacenId, ?int $registradaPor, array $item): void
    {
        $detalleOriginal = DetalleEntrega::query()
            ->where('entrega_uniforme_id', $entrega->getKey())
            ->whereNotNull('unidad_activo_id')
            ->findOr((int) $item['detalle_entrega_id'], fn () => throw new ExcepcionDeNegocioSimple('Esa unidad no pertenece a esta entrega.'));

        $unidad = UnidadActivo::query()->whereKey($detalleOriginal->unidad_activo_id)->lockForUpdate()->firstOrFail();
        $condicion = CondicionUnidadActivo::from($item['condicion']);

        $devolucion->detalles()->create([
            'detalle_entrega_id' => $detalleOriginal->getKey(),
            'activo_id' => $detalleOriginal->activo_id,
            'talla_id' => null,
            'unidad_activo_id' => $unidad->getKey(),
            'cantidad' => 1,
            'condicion_unidad' => $condicion,
            'reingresa_inventario' => false,
        ]);

        $this->unidadesActivo->devolver(
            $unidad,
            $condicion,
            $almacenId,
            $registradaPor,
            Devolucion::class,
            $devolucion->getKey(),
            'Devolución '.$devolucion->folio,
            $entrega->sucursal_id,
        );
    }
}
