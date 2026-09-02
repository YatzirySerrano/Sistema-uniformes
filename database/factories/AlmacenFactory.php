<?php

namespace Database\Factories;

use App\Models\Almacen;
use App\Models\Empresa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Almacen>
 */
class AlmacenFactory extends Factory
{
    protected $model = Almacen::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'nombre' => 'Almacén '.fake()->unique()->city(),
            'codigo' => 'ALM-'.fake()->unique()->numberBetween(1000, 9999),
            'descripcion' => fake()->boolean(30) ? fake()->sentence() : null,
            'direccion' => fake()->streetAddress(),
            'telefono' => fake()->numerify('##########'),
            'correo' => fake()->boolean(40) ? fake()->companyEmail() : null,
            'responsable_colaborador_id' => null,
            'activo' => true,
        ];
    }

    public function inactivo(): static
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}
