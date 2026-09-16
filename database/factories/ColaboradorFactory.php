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
            // Estructuralmente válida (mismo patrón que GuardarColaboradorRequest),
            // sin pretender ser una CURP real de RENAPO.
            'curp' => fake()->unique()->regexify(
                '[A-Z][AEIOU][A-Z]{2}[0-9]{2}(0[1-9]|1[0-2])(0[1-9]|1[0-9]|2[0-8])'
                .'[HM](DF|NL|JC|MC|BC|GT|VZ|SL)[BCDFGHJKLMNPQRSTVWXYZ]{3}[A-Z0-9][0-9]',
            ),
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
