<?php

namespace Database\Factories;

use App\Models\Contrato;
use App\Models\Servicio;
use App\Models\Sucursal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Servicio>
 */
class ServicioFactory extends Factory
{
    protected $model = Servicio::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $nombre = fake()->unique()->city().' '.Str::upper(Str::random(3));

        return [
            'contrato_id' => Contrato::factory(),
            'sucursal_id' => Sucursal::factory(),
            'nombre' => $nombre,
            'codigo' => 'SER-'.fake()->unique()->numberBetween(1000, 9999),
            'direccion' => fake()->boolean(40) ? fake()->address() : null,
            'descripcion' => fake()->boolean(40) ? fake()->sentence() : null,
            'activo' => true,
        ];
    }

    public function inactivo(): static
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}
