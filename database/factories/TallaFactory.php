<?php

namespace Database\Factories;

use App\Models\Empresa;
use App\Models\Talla;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Talla>
 */
class TallaFactory extends Factory
{
    protected $model = Talla::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'valor' => fake()->unique()->randomElement(['XS', 'S', 'M', 'L', 'XL', 'XXL', '28', '30', '32', '34']),
            'orden' => fake()->numberBetween(0, 20),
            'activa' => true,
        ];
    }
}
