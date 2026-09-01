<?php

namespace Database\Factories;

use App\Models\CorreccionEntrega;
use App\Models\EntregaUniforme;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CorreccionEntrega>
 */
class CorreccionEntregaFactory extends Factory
{
    protected $model = CorreccionEntrega::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'entrega_uniforme_id' => EntregaUniforme::factory(),
            'corregida_por' => User::factory(),
            'motivo' => fake()->sentence(),
            'valores_anteriores' => [],
            'valores_nuevos' => [],
        ];
    }
}
