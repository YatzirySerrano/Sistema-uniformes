<?php

namespace Database\Factories;

use App\Models\Almacen;
use App\Models\Empresa;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;

/**
 * @extends Factory<Almacen>
 */
class AlmacenFactory extends Factory
{
    protected $model = Almacen::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => 'Almacén '.fake()->unique()->city(),
            'codigo' => 'ALM-'.fake()->unique()->numberBetween(1000, 9999),
            'descripcion' => fake()->boolean(30) ? fake()->sentence() : null,
            'direccion' => fake()->streetAddress(),
            'telefono' => fake()->numerify('##########'),
            'correo' => fake()->boolean(40) ? fake()->companyEmail() : null,
            'responsable_colaborador_id' => null,
            'activo' => true,
        ];
    }

    /**
     * Vincula el almacén a una o varias empresas abastecidas (pivote
     * `almacen_empresa`). Sin esto, el almacén nace sin empresas.
     */
    public function paraEmpresa(Empresa ...$empresas): static
    {
        return $this->afterCreating(function (Almacen $almacen) use ($empresas): void {
            /** @var Collection<int, Empresa> $coleccion */
            $coleccion = collect($empresas);
            $almacen->empresas()->syncWithoutDetaching($coleccion->pluck('id')->all());
        });
    }

    public function inactivo(): static
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}
