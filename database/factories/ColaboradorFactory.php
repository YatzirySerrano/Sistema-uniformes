<?php

namespace Database\Factories;

use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Sucursal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Colaborador>
 */
class ColaboradorFactory extends Factory
{
    protected $model = Colaborador::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'sucursal_id' => Sucursal::factory(),
            'numero_empleado' => (string) fake()->unique()->numberBetween(1000, 999999),
            'nombre_completo' => fake()->name(),
            'puesto' => fake()->jobTitle(),
            'area' => fake()->randomElement(['Producción', 'Almacén', 'Ventas', 'Administración', 'Logística']),
            'correo' => fake()->optional()->safeEmail(),
            'activo' => true,
        ];
    }

    public function inactivo(): static
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}
