<?php

namespace Database\Factories;

use App\Models\Almacen;
use App\Models\Empresa;
use App\Models\TraspasoInventario;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TraspasoInventario>
 */
class TraspasoInventarioFactory extends Factory
{
    protected $model = TraspasoInventario::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'folio' => 'TRA-'.now()->format('Y').'-'.fake()->unique()->numerify('######'),
            'tipo' => TraspasoInventario::TIPO_MISMA_EMPRESA,
            'empresa_origen_id' => Empresa::factory(),
            'almacen_origen_id' => Almacen::factory(),
            'empresa_destino_id' => Empresa::factory(),
            'almacen_destino_id' => Almacen::factory(),
            'estado' => 'completado',
            'motivo' => fake()->boolean(50) ? fake()->sentence() : null,
            'realizado_por' => User::factory(),
            'ocurrido_en' => now(),
        ];
    }
}
