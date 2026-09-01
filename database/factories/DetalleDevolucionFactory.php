<?php

namespace Database\Factories;

use App\Enums\CondicionDevolucion;
use App\Models\DetalleDevolucion;
use App\Models\Devolucion;
use App\Models\Prenda;
use App\Models\Talla;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DetalleDevolucion>
 */
class DetalleDevolucionFactory extends Factory
{
    protected $model = DetalleDevolucion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'devolucion_id' => Devolucion::factory(),
            'prenda_id' => Prenda::factory(),
            'talla_id' => Talla::factory(),
            'cantidad' => fake()->numberBetween(1, 3),
            'condicion' => CondicionDevolucion::Reutilizable,
            'reingresa_inventario' => true,
        ];
    }
}
