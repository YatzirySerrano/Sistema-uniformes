<?php

namespace Database\Factories;

use App\Models\Activo;
use App\Models\Conjunto;
use App\Models\ConjuntoComponente;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConjuntoComponente>
 */
class ConjuntoComponenteFactory extends Factory
{
    protected $model = ConjuntoComponente::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conjunto_id' => Conjunto::factory(),
            'activo_id' => Activo::factory(),
            'cantidad_requerida' => 1,
            'talla_id' => null,
            'talla_libre' => false,
        ];
    }
}
