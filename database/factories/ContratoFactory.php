<?php

namespace Database\Factories;

use App\Models\Contrato;
use App\Models\Empresa;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Contrato>
 */
class ContratoFactory extends Factory
{
    protected $model = Contrato::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $nombre = fake()->unique()->company().' '.Str::upper(Str::random(3));

        return [
            'empresa_id' => Empresa::factory(),
            'nombre' => $nombre,
            'codigo' => 'CON-'.fake()->unique()->numberBetween(1000, 9999),
            'descripcion' => fake()->boolean(40) ? fake()->sentence() : null,
            'fecha_inicio' => null,
            'fecha_fin' => null,
            'activo' => true,
        ];
    }

    public function inactivo(): static
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}
