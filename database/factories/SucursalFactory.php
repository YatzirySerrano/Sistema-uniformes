<?php

namespace Database\Factories;

use App\Models\Empresa;
use App\Models\Sucursal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Sucursal>
 */
class SucursalFactory extends Factory
{
    protected $model = Sucursal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'codigo' => Str::upper(fake()->unique()->bothify('SUC-###')),
            'nombre' => 'Sucursal '.fake()->city(),
            'direccion' => fake()->address(),
            'telefono' => fake()->phoneNumber(),
            'activa' => true,
        ];
    }
}
