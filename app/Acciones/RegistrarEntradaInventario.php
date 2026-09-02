<?php

namespace App\Acciones;

use App\Enums\TipoMovimiento;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\Activo;
use App\Models\MovimientoInventario;
use App\Models\Sucursal;
use App\Models\Talla;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\DB;

/**
 * Registra una o varias entradas de inventario (compras, recepción de almacén,
 * carga inicial). Valida que activo/talla/sucursal pertenezcan a la empresa.
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
        int $sucursalId,
        array $items,
        string $motivo,
        ?int $realizadoPor,
        bool $cargaInicial = false,
        ?string $notas = null,
    ): array {
        Sucursal::query()->where('empresa_id', $empresaId)->findOr($sucursalId, fn () => throw new ExcepcionDeNegocioSimple('La sucursal indicada no pertenece a esta empresa.'));

        $activoIds = array_column($items, 'activo_id');
        $tallaIds = array_column($items, 'talla_id');

        $activosValidos = Activo::query()->where('empresa_id', $empresaId)->whereIn('id', $activoIds)->pluck('id')->all();
        $tallasValidas = Talla::query()->where('empresa_id', $empresaId)->whereIn('id', $tallaIds)->pluck('id')->all();

        return DB::transaction(function () use ($items, $empresaId, $sucursalId, $motivo, $realizadoPor, $cargaInicial, $notas, $activosValidos, $tallasValidas): array {
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
                    sucursalId: $sucursalId,
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
                'sucursal_id' => $sucursalId,
                'descripcion' => count($movimientos).' movimiento(s) de entrada registrados. Motivo: '.$motivo,
            ]);

            return $movimientos;
        });
    }
}
