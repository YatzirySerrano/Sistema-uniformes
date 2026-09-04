<?php

namespace Database\Factories;

use App\Models\CategoriaActivo;
use App\Models\Empresa;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;

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
            'tipo_activo_id' => null,
            'nombre' => fake()->unique()->company(),
            'codigo' => null,
            'activa' => true,
        ];
    }

    public function inactiva(): static
    {
        return $this->state(fn (): array => ['activa' => false]);
    }

    /**
     * Habilita la categoría (catálogo compartido) para una o varias empresas.
     */
    public function paraEmpresa(Empresa ...$empresas): static
    {
        return $this->afterCreating(function (CategoriaActivo $categoria) use ($empresas): void {
            /** @var Collection<int, Empresa> $coleccion */
            $coleccion = collect($empresas);
            $categoria->empresas()->syncWithoutDetaching($coleccion->pluck('id')->all());
        });
    }
}
