<?php

namespace Database\Factories;

use App\Models\InventarioFisico;
use App\Models\InventarioFisicoFirma;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InventarioFisicoFirma>
 */
class InventarioFisicoFirmaFactory extends Factory
{
    protected $model = InventarioFisicoFirma::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inventario_fisico_id' => InventarioFisico::factory(),
            'ruta_firma' => 'firmas/inventario-fisico/1/'.Str::uuid().'.png',
            'hash_firma' => hash('sha256', Str::random(32)),
            'nombre_firmante' => fake()->name(),
            'texto_aceptado' => 'Declaro que realicé personalmente este inventario físico.',
            'aceptado_en' => now(),
            'firmado_por' => User::factory(),
        ];
    }
}
