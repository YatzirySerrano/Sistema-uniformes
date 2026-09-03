<?php

namespace App\Acciones;

use App\Enums\TipoMovimiento;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\MovimientoInventario;
use App\Models\Talla;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\DB;

/**
 * Registra una o varias entradas de inventario (compras, recepción, carga
 * inicial) en un ALMACÉN. Valida que almacén/activo/talla pertenezcan a la
 * empresa y que el almacén esté activo.
 */
class RegistrarEntradaInventario
{
    public function __construct(
        private readonly ServicioInventario $inventario,
        private readonly ServicioAuditoria $auditoria,
    ) {}

    /**
     * @param  array<int, array{activo_id: int, talla_id: int, cantidad: int}>  $items
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
        $almacen = Almacen::query()->where('empresa_id', $empresaId)
            ->findOr($almacenId, fn () => throw new ExcepcionDeNegocioSimple('El almacén indicado no pertenece a esta empresa.'));

        if (! $almacen->activo) {
            throw new ExcepcionDeNegocioSimple('El almacén está desactivado; no admite entradas de inventario.');
        }

        $activoIds = array_column($items, 'activo_id');
        $tallaIds = array_column($items, 'talla_id');

        $activosValidos = Activo::query()->where('empresa_id', $empresaId)->whereIn('id', $activoIds)->pluck('id')->all();
        $tallasValidas = Talla::query()->where('empresa_id', $empresaId)->whereIn('id', $tallaIds)->pluck('id')->all();

        return DB::transaction(function () use ($items, $empresaId, $almacenId, $motivo, $realizadoPor, $cargaInicial, $notas, $activosValidos, $tallasValidas): array {
            $movimientos = [];

            foreach ($items as $item) {
                if ((int) $item['cantidad'] <= 0) {
                    continue;
                }
                if (! in_array((int) $item['activo_id'], $activosValidos, true) || ! in_array((int) $item['talla_id'], $tallasValidas, true)) {
                    throw new ExcepcionDeNegocioSimple('Un activo o talla seleccionado no pertenece a esta empresa.');
                }

                $movimientos[] = $this->inventario->registrarMovimiento(new MovimientoInventarioDatos(
                    empresaId: $empresaId,
                    almacenId: $almacenId,
                    activoId: (int) $item['activo_id'],
                    tallaId: (int) $item['talla_id'],
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
                'descripcion' => count($movimientos).' movimiento(s) de entrada en almacén #'.$almacenId.'. Motivo: '.$motivo,
            ]);

            return $movimientos;
        });
    }
}
