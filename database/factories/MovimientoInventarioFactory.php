<?php

namespace Database\Factories;

use App\Enums\DireccionMovimiento;
use App\Enums\TipoMovimiento;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Empresa;
use App\Models\MovimientoInventario;
use App\Models\Talla;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MovimientoInventario>
 */
class MovimientoInventarioFactory extends Factory
{
    protected $model = MovimientoInventario::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'almacen_id' => Almacen::factory(),
            'sucursal_id' => null,
            'activo_id' => Activo::factory(),
            'talla_id' => Talla::factory(),
            'tipo' => TipoMovimiento::Entrada,
            'direccion' => DireccionMovimiento::Entrada,
            'cantidad' => fake()->numberBetween(1, 20),
            'existencia_anterior' => 0,
            'existencia_resultante' => fake()->numberBetween(1, 20),
            'ocurrido_en' => now(),
        ];
    }
}
