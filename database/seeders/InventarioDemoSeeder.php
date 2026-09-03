<?php

namespace Database\Seeders;

use App\Enums\TipoMovimiento;
use App\Models\Almacen;
use App\Models\User;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use Illuminate\Database\Seeder;

/**
 * Inventario de demostración POR ALMACÉN. El origen físico del stock es el
 * almacén (no la sucursal). Sólo se cargan activos "por cantidad".
 */
class InventarioDemoSeeder extends Seeder
{
    public function run(): void
    {
        $inventario = app(ServicioInventario::class);
        $usuario = User::query()->where('email', 'admin.ab@example.test')->first();

        $almacenes = Almacen::query()
            ->with(['empresa.activos.tallas'])
            ->where('activo', true)
            ->get();

        foreach ($almacenes as $a => $almacen) {
            foreach ($almacen->empresa->activos as $activo) {
                if ($activo->tipo_control->value !== 'cantidad') {
                    continue;
                }

                foreach ($activo->tallas as $t => $talla) {
                    $cantidad = match (($a + $t) % 5) {
                        0 => 0,
                        1 => random_int(2, 5),
                        default => random_int(20, 120),
                    };

                    $inventario->ajustarMinimo($almacen->empresa_id, $almacen->id, $activo->id, $talla->id, random_int(3, 8));

                    if ($cantidad > 0) {
                        $inventario->registrarMovimiento(new MovimientoInventarioDatos(
                            empresaId: $almacen->empresa_id,
                            almacenId: $almacen->id,
                            activoId: $activo->id,
                            tallaId: $talla->id,
                            tipo: TipoMovimiento::Inicial,
                            cantidad: $cantidad,
                            realizadoPor: $usuario?->id,
                            referenciaTipo: 'seeder',
                            motivo: 'Carga inicial de demostración',
                        ));
                    }
                }
            }
        }
    }
}
