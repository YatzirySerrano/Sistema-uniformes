<?php

namespace Database\Factories;

use App\Models\Empresa;
use App\Models\TipoActivo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TipoActivo>
 */
class TipoActivoFactory extends Factory
{
    protected $model = TipoActivo::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'nombre' => fake()->unique()->randomElement([
                'Prenda', 'Equipo de cómputo', 'Dispositivo móvil',
                'Electrónico', 'Accesorio', 'Herramienta / Equipo', 'Otro',
            ]),
            'codigo' => 'TAC-'.fake()->unique()->numberBetween(1000, 9999),
            'activo' => true,
        ];
    }
}
