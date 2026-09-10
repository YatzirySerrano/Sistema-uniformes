<?php

namespace Database\Factories;

use App\Enums\TipoControlActivo;
use App\Models\Activo;
use App\Models\TraspasoInventario;
use App\Models\TraspasoRenglon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TraspasoRenglon>
 */
class TraspasoRenglonFactory extends Factory
{
    protected $model = TraspasoRenglon::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'traspaso_inventario_id' => TraspasoInventario::factory(),
            'control' => TipoControlActivo::Cantidad,
            'activo_origen_id' => Activo::factory(),
            'activo_destino_id' => Activo::factory(),
            'talla_id' => null,
            'unidad_activo_id' => null,
            'cantidad' => fake()->numberBetween(1, 50),
            'activo_origen_nombre_snapshot' => fake()->word(),
            'activo_destino_nombre_snapshot' => fake()->word(),
            'talla_valor_snapshot' => null,
            'activo_destino_creado' => false,
            'unidad_codigo_snapshot' => null,
            'movimiento_salida_id' => null,
            'movimiento_entrada_id' => null,
        ];
    }
}
