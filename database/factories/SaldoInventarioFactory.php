<?php

namespace Database\Factories;

use App\Models\Empresa;
use App\Models\Prenda;
use App\Models\SaldoInventario;
use App\Models\Sucursal;
use App\Models\Talla;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaldoInventario>
 */
class SaldoInventarioFactory extends Factory
{
    protected $model = SaldoInventario::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'sucursal_id' => Sucursal::factory(),
            'prenda_id' => Prenda::factory(),
            'talla_id' => Talla::factory(),
            'cantidad' => fake()->numberBetween(0, 100),
            'minimo' => fake()->numberBetween(0, 10),
        ];
    }
}
