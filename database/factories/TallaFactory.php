<?php

namespace Database\Factories;

use App\Models\Empresa;
use App\Models\Talla;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;

/**
 * @extends Factory<Talla>
 */
class TallaFactory extends Factory
{
    protected $model = Talla::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'valor' => fake()->unique()->bothify('V-###'),
            'orden' => fake()->numberBetween(0, 20),
            'activa' => true,
        ];
    }

    /**
     * Habilita la variante (catálogo compartido) para una o varias empresas.
     */
    public function paraEmpresa(Empresa ...$empresas): static
    {
        return $this->afterCreating(function (Talla $talla) use ($empresas): void {
            /** @var Collection<int, Empresa> $coleccion */
            $coleccion = collect($empresas);
            $talla->empresas()->syncWithoutDetaching($coleccion->pluck('id')->all());
        });
    }
}
