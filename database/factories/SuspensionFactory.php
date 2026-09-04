<?php

namespace Database\Factories;

use App\Models\Suspension;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Suspension>
 */
class SuspensionFactory extends Factory
{
    protected $model = Suspension::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'entidad_type' => 'App\\Models\\Sucursal',
            'entidad_id' => 1,
            'columna_activo' => 'activa',
            'causante_type' => 'App\\Models\\Empresa',
            'causante_id' => 1,
            'motivo' => fake()->sentence(),
            'suspendida_en' => now(),
        ];
    }

    public function levantada(): static
    {
        return $this->state(fn (): array => ['levantada_en' => now()]);
    }
}
