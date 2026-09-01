<?php

namespace App\Acciones;

use App\Enums\EstadoEntrega;
use App\Enums\TipoMovimiento;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\CorreccionEntrega;
use App\Models\EntregaUniforme;
use App\Models\Prenda;
use App\Models\Talla;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\DB;

/**
 * Corrección administrativa de una entrega ya firmada (o pendiente). Conserva la
 * entrega original y el acuse inmutable: registra el antes/después, compensa el
 * inventario con movimientos de corrección y deja constancia en auditoría.
 */
class CorregirEntrega
{
    public function __construct(
        private readonly ServicioInventario $inventario,
        private readonly ServicioAuditoria $auditoria,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $itemsNuevos
     */
    public function ejecutar(EntregaUniforme $entrega, array $itemsNuevos, string $motivo, ?int $corregidaPor): EntregaUniforme
    {
        if (trim($motivo) === '') {
            throw new ExcepcionDeNegocioSimple('El motivo de la corrección es obligatorio.');
        }

        if ($entrega->estado === EstadoEntrega::Anulada) {
            throw new ExcepcionDeNegocioSimple('No se puede corregir una entrega anulada.');
        }

        $entrega->loadMissing('detalles');
        $empresaId = $entrega->empresa_id;
        $sucursalId = $entrega->sucursal_id;

        $nuevos = $this->consolidar($itemsNuevos);
        if ($nuevos === []) {
            throw new ExcepcionDeNegocioSimple('La entrega corregida debe conservar al menos una prenda.');
        }

        $prendas = Prenda::query()->where('empresa_id', $empresaId)->whereIn('id', array_column($nuevos, 'prenda_id'))->get()->keyBy('id');
        $tallas = Talla::query()->where('empresa_id', $empresaId)->whereIn('id', array_column($nuevos, 'talla_id'))->get()->keyBy('id');

        $anteriores = [];
        foreach ($entrega->detalles as $d) {
            $anteriores[$d->prenda_id.'-'.$d->talla_id] = (int) $d->cantidad;
        }

        $valoresAnteriores = $entrega->detalles->map(fn ($d): array => [
            'prenda_id' => $d->prenda_id, 'talla_id' => $d->talla_id, 'cantidad' => (int) $d->cantidad,
        ])->all();

        return DB::transaction(function () use ($entrega, $nuevos, $anteriores, $valoresAnteriores, $motivo, $corregidaPor, $empresaId, $sucursalId, $prendas, $tallas): EntregaUniforme {
            $clavesNuevas = [];

            foreach ($nuevos as $item) {
                if (! $prendas->has($item['prenda_id']) || ! $tallas->has($item['talla_id'])) {
                    throw new ExcepcionDeNegocioSimple('Una prenda o talla de la corrección no pertenece a esta empresa.');
                }

                $clave = $item['prenda_id'].'-'.$item['talla_id'];
                $clavesNuevas[] = $clave;
                $anterior = $anteriores[$clave] ?? 0;
                $delta = $item['cantidad'] - $anterior;

                if ($delta > 0) {
                    // Se entregó de más: descontar del inventario.
                    $this->inventario->registrarMovimiento(new MovimientoInventarioDatos(
                        empresaId: $empresaId, sucursalId: $sucursalId,
                        prendaId: $item['prenda_id'], tallaId: $item['talla_id'],
                        tipo: TipoMovimiento::AjusteSalida, cantidad: $delta,
                        realizadoPor: $corregidaPor,
                        referenciaTipo: EntregaUniforme::class, referenciaId: $entrega->getKey(),
                        motivo: 'Corrección de entrega '.$entrega->folio,
                    ));
                } elseif ($delta < 0) {
                    // Se entregó de menos: reintegrar al inventario.
                    $this->inventario->registrarMovimiento(new MovimientoInventarioDatos(
                        empresaId: $empresaId, sucursalId: $sucursalId,
                        prendaId: $item['prenda_id'], tallaId: $item['talla_id'],
                        tipo: TipoMovimiento::Correccion, cantidad: abs($delta),
                        realizadoPor: $corregidaPor,
                        referenciaTipo: EntregaUniforme::class, referenciaId: $entrega->getKey(),
                        motivo: 'Corrección de entrega '.$entrega->folio,
                    ));
                }

                $entrega->detalles()->updateOrCreate(
                    ['prenda_id' => $item['prenda_id'], 'talla_id' => $item['talla_id']],
                    [
                        'cantidad' => $item['cantidad'],
                        'prenda_nombre_snapshot' => $prendas[$item['prenda_id']]->nombre,
                        'talla_valor_snapshot' => $tallas[$item['talla_id']]->valor,
                    ],
                );
            }

            // Prendas retiradas por completo en la corrección: reintegrar y borrar.
            foreach ($entrega->detalles()->get() as $detalle) {
                $clave = $detalle->prenda_id.'-'.$detalle->talla_id;
                if (in_array($clave, $clavesNuevas, true)) {
                    continue;
                }

                $this->inventario->registrarMovimiento(new MovimientoInventarioDatos(
                    empresaId: $empresaId, sucursalId: $sucursalId,
                    prendaId: $detalle->prenda_id, tallaId: $detalle->talla_id,
                    tipo: TipoMovimiento::Correccion, cantidad: (int) $detalle->cantidad,
                    realizadoPor: $corregidaPor,
                    referenciaTipo: EntregaUniforme::class, referenciaId: $entrega->getKey(),
                    motivo: 'Corrección de entrega '.$entrega->folio.' (prenda retirada)',
                ));

                $detalle->delete();
            }

            $entrega->update([
                'estado' => $entrega->estaFirmada() ? EstadoEntrega::Corregida : $entrega->estado,
            ]);

            $correccion = CorreccionEntrega::query()->create([
                'entrega_uniforme_id' => $entrega->getKey(),
                'corregida_por' => $corregidaPor,
                'motivo' => $motivo,
                'valores_anteriores' => $valoresAnteriores,
                'valores_nuevos' => $nuevos,
            ]);

            $this->auditoria->registrar('entregas', 'corregir', [
                'tipo_entidad' => EntregaUniforme::class,
                'entidad_id' => $entrega->getKey(),
                'sucursal_id' => $sucursalId,
                'motivo' => $motivo,
                'descripcion' => 'Corrección '.$correccion->getKey().' aplicada a la entrega '.$entrega->folio,
                'valores_anteriores' => $valoresAnteriores,
                'valores_nuevos' => $nuevos,
            ]);

            return $entrega->load('detalles');
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array{prenda_id: int, talla_id: int, cantidad: int}>
     */
    private function consolidar(array $items): array
    {
        $mapa = [];
        foreach ($items as $item) {
            $cantidad = (int) ($item['cantidad'] ?? 0);
            if ($cantidad <= 0) {
                continue;
            }
            $clave = ((int) $item['prenda_id']).'-'.((int) $item['talla_id']);
            $mapa[$clave] ??= ['prenda_id' => (int) $item['prenda_id'], 'talla_id' => (int) $item['talla_id'], 'cantidad' => 0];
            $mapa[$clave]['cantidad'] += $cantidad;
        }

        return array_values($mapa);
    }
}
