<?php

namespace Database\Seeders;

use App\Enums\TipoMovimiento;
use App\Models\Empresa;
use App\Models\User;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use Illuminate\Database\Seeder;

class InventarioDemoSeeder extends Seeder
{
    public function run(): void
    {
        $inventario = app(ServicioInventario::class);
        $usuario = User::query()->where('email', 'admin.ab@example.test')->first();

        foreach (Empresa::with(['sucursales', 'activos.tallas'])->get() as $empresa) {
            foreach ($empresa->sucursales as $s => $sucursal) {
                foreach ($empresa->activos as $activo) {
                    foreach ($activo->tallas as $t => $talla) {
                        // Escenarios: mayoría con stock normal, algunas bajo mínimo, algunas en cero.
                        $cantidad = match (($s + $t) % 5) {
                            0 => 0,
                            1 => random_int(2, 5),
                            default => random_int(20, 120),
                        };

                        $inventario->ajustarMinimo($empresa->id, $sucursal->id, $activo->id, $talla->id, random_int(3, 8));

                        if ($cantidad > 0) {
                            $inventario->registrarMovimiento(new MovimientoInventarioDatos(
                                empresaId: $empresa->id,
                                sucursalId: $sucursal->id,
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
}
