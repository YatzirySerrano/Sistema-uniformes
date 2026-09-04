<?php

namespace App\Acciones;

use App\Enums\TipoControlActivo;
use App\Enums\TipoMovimiento;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\MovimientoInventario;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\DB;

/**
 * Registra una o varias entradas de inventario (compras, recepción, carga
 * inicial) en un ALMACÉN. Valida que almacén / activo / talla pertenezcan a la
 * empresa y que el almacén esté activo. Un activo por cantidad sin variantes
 * usa `talla_id = NULL`.
 */
class RegistrarEntradaInventario
{
    public function __construct(
        private readonly ServicioInventario $inventario,
        private readonly ServicioAuditoria $auditoria,
    ) {}

    /**
     * @param  array<int, array{activo_id: int, talla_id?: int|null, cantidad: int}>  $items
     * @return array<int, MovimientoInventario>
     */
    public function ejecutar(
        int $empresaId,
        int $almacenId,
        array $items,
        string $motivo,
        ?int $realizadoPor,
        bool $cargaInicial = false,
        ?string $notas = null,
    ): array {
        $almacen = Almacen::query()->paraEmpresa($empresaId)
            ->findOr($almacenId, fn () => throw new ExcepcionDeNegocioSimple('El almacén indicado no abastece a esta empresa.'));

        if (! $almacen->activo) {
            throw new ExcepcionDeNegocioSimple('El almacén está desactivado; no admite entradas de inventario.');
        }

        $activos = Activo::query()
            ->where('empresa_id', $empresaId)
            ->withCount('tallas')
            ->whereIn('id', array_column($items, 'activo_id'))
            ->get()
            ->keyBy('id');

        return DB::transaction(function () use ($items, $empresaId, $almacenId, $motivo, $realizadoPor, $cargaInicial, $notas, $activos): array {
            $movimientos = [];

            foreach ($items as $item) {
                if ((int) $item['cantidad'] <= 0) {
                    continue;
                }

                /** @var Activo|null $activo */
                $activo = $activos->get((int) $item['activo_id']);

                if ($activo === null) {
                    throw new ExcepcionDeNegocioSimple('Un activo seleccionado no pertenece a esta empresa.');
                }
                if ($activo->tipo_control === TipoControlActivo::SeguimientoIndividual) {
                    throw new ExcepcionDeNegocioSimple('Los activos de seguimiento individual no se registran por esta pantalla.');
                }

                $tallaId = ($item['talla_id'] ?? null) ?: null;

                // Variante elegible = asociada al activo y activa globalmente.
                $elegibles = (int) $activo->tallas_count > 0
                    ? $activo->tallasElegibles()->pluck('id')->all()
                    : [];

                if ($elegibles === []) {
                    $tallaId = null; // activo por cantidad sin variantes
                } elseif ($tallaId === null || ! in_array((int) $tallaId, $elegibles, true)) {
                    throw new ExcepcionDeNegocioSimple('La variante indicada no corresponde al activo o está desactivada.');
                }

                $movimientos[] = $this->inventario->registrarMovimiento(new MovimientoInventarioDatos(
                    empresaId: $empresaId,
                    almacenId: $almacenId,
                    activoId: (int) $item['activo_id'],
                    tallaId: $tallaId === null ? null : (int) $tallaId,
                    tipo: $cargaInicial ? TipoMovimiento::Inicial : TipoMovimiento::Entrada,
                    cantidad: (int) $item['cantidad'],
                    realizadoPor: $realizadoPor,
                    referenciaTipo: 'entrada_manual',
                    motivo: $motivo,
                    notas: $notas,
                ));
            }

            if ($movimientos === []) {
                throw new ExcepcionDeNegocioSimple('No se registró ninguna entrada. Indica cantidades mayores a cero.');
            }

            $this->auditoria->registrar('inventario', $cargaInicial ? 'carga_inicial' : 'entrada', [
                'empresa_id' => $empresaId,
                'descripcion' => count($movimientos).' movimiento(s) de entrada en almacén #'.$almacenId.'. Motivo: '.$motivo,
            ]);

            return $movimientos;
        });
    }
}
