<?php

namespace Database\Factories;

use App\Models\Activo;
use App\Models\InventarioFisico;
use App\Models\InventarioFisicoExistencia;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventarioFisicoExistencia>
 */
class InventarioFisicoExistenciaFactory extends Factory
{
    protected $model = InventarioFisicoExistencia::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inventario_fisico_id' => InventarioFisico::factory(),
            'activo_id' => Activo::factory(),
            'talla_id' => null,
            'cantidad_esperada' => fake()->numberBetween(1, 50),
            'cantidad_contada' => null,
            'verificada_por' => null,
            'verificada_en' => null,
        ];
    }

    public function verificada(?int $contada = null): static
    {
        return $this->state(fn (array $atributos): array => [
            'cantidad_contada' => $contada ?? $atributos['cantidad_esperada'],
            'verificada_en' => now(),
        ]);
    }
}
