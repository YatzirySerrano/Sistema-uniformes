<?php

namespace Database\Factories;

use App\Enums\TipoControlActivo;
use App\Models\Activo;
use App\Models\Empresa;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Activo>
 */
class ActivoFactory extends Factory
{
    protected $model = Activo::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $nombre = fake()->randomElement(['Camisa Operativa', 'Pantalón Operativo', 'Playera', 'Chamarra', 'Chaleco', 'Gorra']);

        return [
            'empresa_id' => Empresa::factory(),
            'tipo_activo_id' => null,
            'nombre' => $nombre.' '.Str::random(3),
            'descripcion' => fake()->sentence(),
            'categoria' => fake()->randomElement(['Superior', 'Inferior', 'Abrigo', 'Accesorio', 'Calzado']),
            'tipo_control' => TipoControlActivo::Cantidad,
            'codigo' => Str::upper(fake()->unique()->bothify('ACT-###')),
            'activo' => true,
        ];
    }

    public function serializado(): static
    {
        return $this->state(fn (): array => ['tipo_control' => TipoControlActivo::Serializado]);
    }

    public function inactivo(): static
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}
