<?php

namespace Database\Factories;

use App\Models\CategoriaActivo;
use App\Models\Empresa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CategoriaActivo>
 */
class CategoriaActivoFactory extends Factory
{
    protected $model = CategoriaActivo::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'tipo_activo_id' => null,
            'nombre' => fake()->unique()->randomElement([
                'Camisola', 'Pantalón', 'Playera', 'Chamarra', 'Gorra',
                'Laptop', 'Teléfono celular', 'Tablet', 'Mouse', 'Teclado',
            ]),
            'codigo' => null,
            'activa' => true,
        ];
    }

    public function inactiva(): static
    {
        return $this->state(fn (): array => ['activa' => false]);
    }
}
