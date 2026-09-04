<?php

namespace Database\Factories;

use App\Models\TipoActivo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TipoActivo>
 */
class TipoActivoFactory extends Factory
{
    protected $model = TipoActivo::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => fake()->unique()->company(),
            'codigo' => 'TAC-'.fake()->unique()->numberBetween(1000, 9999),
            'activo' => true,
        ];
    }
}
