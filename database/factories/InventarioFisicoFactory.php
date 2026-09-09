<?php

namespace Database\Factories;

use App\Enums\EstadoInventarioFisico;
use App\Models\Empresa;
use App\Models\InventarioFisico;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventarioFisico>
 */
class InventarioFisicoFactory extends Factory
{
    protected $model = InventarioFisico::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'usuario_id' => User::factory(),
            'almacen_id' => null,
            'folio' => 'INVF-'.now()->year.'-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'nombre' => 'Inventario físico '.fake()->monthName().' '.now()->year,
            'estado' => EstadoInventarioFisico::EnProceso,
            'observaciones' => null,
            'finalizado_en' => null,
        ];
    }

    public function finalizado(): static
    {
        return $this->state(fn (): array => [
            'estado' => EstadoInventarioFisico::Finalizado,
            'finalizado_en' => now(),
        ]);
    }
}
