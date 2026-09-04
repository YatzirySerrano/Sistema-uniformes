<?php

namespace Database\Factories;

use App\Models\Conjunto;
use App\Models\Empresa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conjunto>
 */
class ConjuntoFactory extends Factory
{
    protected $model = Conjunto::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'nombre' => fake()->unique()->words(2, true),
            'codigo' => null,
            'descripcion' => fake()->sentence(),
            'activo' => true,
        ];
    }

    public function inactivo(): static
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}
