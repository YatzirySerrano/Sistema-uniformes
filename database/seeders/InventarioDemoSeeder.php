<?php

namespace Database\Seeders;

use App\Enums\TipoMovimiento;
use App\Models\Almacen;
use App\Models\User;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use Illuminate\Database\Seeder;

/**
 * Inventario de demostración por EMPRESA + ALMACÉN. Un almacén compartido carga
 * existencias separadas para cada una de sus empresas. Sólo se cargan activos
 * "por cantidad".
 */
class InventarioDemoSeeder extends Seeder
{
    public function run(): void
    {
        $inventario = app(ServicioInventario::class);
        $usuario = User::query()->where('email', 'admin.ab@example.test')->first();

        $almacenes = Almacen::query()
            ->with(['empresas.activos.tallas'])
            ->where('activo', true)
            ->get();

        foreach ($almacenes as $a => $almacen) {
            foreach ($almacen->empresas as $empresa) {
                foreach ($empresa->activos as $activo) {
                    if ($activo->tipo_control->value !== 'cantidad') {
                        continue;
                    }

                    // Un activo por cantidad sin variantes usa talla_id = NULL.
                    $tallasIds = $activo->tallas->isEmpty() ? [null] : $activo->tallas->pluck('id')->all();

                    foreach ($tallasIds as $t => $tallaId) {
                        $cantidad = match (($a + $t) % 5) {
                            0 => 0,
                            1 => random_int(2, 5),
                            default => random_int(20, 120),
                        };

                        $inventario->ajustarMinimo($empresa->id, $almacen->id, $activo->id, $tallaId, random_int(3, 8));

                        if ($cantidad > 0) {
                            $inventario->registrarMovimiento(new MovimientoInventarioDatos(
                                empresaId: $empresa->id,
                                almacenId: $almacen->id,
                                activoId: $activo->id,
                                tallaId: $tallaId,
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
}
