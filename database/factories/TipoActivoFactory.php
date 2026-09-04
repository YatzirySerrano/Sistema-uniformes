<?php

namespace Database\Factories;

use App\Models\Empresa;
use App\Models\TipoActivo;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;

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
            'nombre' => fake()->unique()->company(),
            'codigo' => 'TAC-'.fake()->unique()->numberBetween(1000, 9999),
            'activo' => true,
        ];
    }

    /**
     * Habilita el tipo (catálogo compartido) para una o varias empresas.
     */
    public function paraEmpresa(Empresa ...$empresas): static
    {
        return $this->afterCreating(function (TipoActivo $tipo) use ($empresas): void {
            /** @var Collection<int, Empresa> $coleccion */
            $coleccion = collect($empresas);
            $tipo->empresas()->syncWithoutDetaching($coleccion->pluck('id')->all());
        });
    }
}
