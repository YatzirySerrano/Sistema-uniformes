<?php

namespace Database\Factories;

use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Empresa;
use App\Models\SaldoInventario;
use App\Models\Sucursal;
use App\Models\Talla;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaldoInventario>
 */
class SaldoInventarioFactory extends Factory
{
    protected $model = SaldoInventario::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'almacen_id' => Almacen::factory(),
            'sucursal_id' => null,
            'activo_id' => Activo::factory(),
            'talla_id' => Talla::factory(),
            'cantidad' => fake()->numberBetween(0, 100),
            'minimo' => fake()->numberBetween(0, 10),
        ];
    }

    /**
     * Fila legacy pendiente de migración (asociada a sucursal, sin almacén).
     */
    public function legacy(?Sucursal $sucursal = null): static
    {
        return $this->state(fn (): array => [
            'almacen_id' => null,
            'sucursal_id' => $sucursal !== null ? $sucursal->id : Sucursal::factory(),
        ]);
    }
}
