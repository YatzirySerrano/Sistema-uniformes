<?php

namespace Database\Factories;

use App\Models\CategoriaActivo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CategoriaActivo>
 */
class CategoriaActivoFactory extends Factory
{
    protected $model = CategoriaActivo::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tipo_activo_id' => null,
            'nombre' => fake()->unique()->company(),
            'codigo' => null,
            'activa' => true,
        ];
    }

    public function inactiva(): static
    {
        return $this->state(fn (): array => ['activa' => false]);
    }
}
