<?php

namespace Database\Factories;

use App\Models\InventarioFisico;
use App\Models\InventarioFisicoUnidad;
use App\Models\UnidadActivo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventarioFisicoUnidad>
 */
class InventarioFisicoUnidadFactory extends Factory
{
    protected $model = InventarioFisicoUnidad::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inventario_fisico_id' => InventarioFisico::factory(),
            'unidad_activo_id' => UnidadActivo::factory(),
            'esperada' => true,
            'escaneado_en' => null,
            'escaneado_por' => null,
        ];
    }

    public function escaneada(): static
    {
        return $this->state(fn (): array => ['escaneado_en' => now()]);
    }

    public function noEsperada(): static
    {
        return $this->state(fn (): array => ['esperada' => false, 'escaneado_en' => now()]);
    }
}
