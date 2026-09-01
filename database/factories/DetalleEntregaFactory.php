<?php

namespace Database\Factories;

use App\Models\DetalleEntrega;
use App\Models\EntregaUniforme;
use App\Models\Prenda;
use App\Models\Talla;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DetalleEntrega>
 */
class DetalleEntregaFactory extends Factory
{
    protected $model = DetalleEntrega::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'entrega_uniforme_id' => EntregaUniforme::factory(),
            'prenda_id' => Prenda::factory(),
            'talla_id' => Talla::factory(),
            'cantidad' => fake()->numberBetween(1, 5),
            'prenda_nombre_snapshot' => fake()->word(),
            'talla_valor_snapshot' => 'M',
        ];
    }
}
