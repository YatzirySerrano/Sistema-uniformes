<?php

namespace Database\Factories;

use App\Models\Empresa;
use App\Models\Prenda;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Prenda>
 */
class PrendaFactory extends Factory
{
    protected $model = Prenda::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $nombre = fake()->randomElement(['Camisa Operativa', 'Pantalón Operativo', 'Playera', 'Chamarra', 'Chaleco', 'Gorra']);

        return [
            'empresa_id' => Empresa::factory(),
            'nombre' => $nombre.' '.Str::random(3),
            'descripcion' => fake()->sentence(),
            'categoria' => fake()->randomElement(['Superior', 'Inferior', 'Abrigo', 'Accesorio', 'Calzado']),
            'codigo_interno' => Str::upper(fake()->unique()->bothify('PRD-###')),
            'activa' => true,
        ];
    }
}
