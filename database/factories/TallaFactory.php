<?php

namespace Database\Factories;

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
            'valor' => fake()->unique()->bothify('V-###'),
            'orden' => fake()->numberBetween(0, 20),
            'activa' => true,
        ];
    }
}
