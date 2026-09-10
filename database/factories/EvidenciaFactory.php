<?php

namespace Database\Factories;

use App\Models\DetalleEntrega;
use App\Models\Evidencia;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Evidencia>
 */
class EvidenciaFactory extends Factory
{
    protected $model = Evidencia::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'evidenciable_type' => DetalleEntrega::class,
            'evidenciable_id' => DetalleEntrega::factory(),
            'disco' => 'local',
            'ruta' => 'evidencias/pruebas/'.Str::uuid().'.jpg',
            'nombre_original' => 'evidencia.jpg',
            'mime' => 'image/jpeg',
            'extension' => 'jpg',
            'peso_bytes' => fake()->numberBetween(20_000, 900_000),
            'hash_sha256' => hash('sha256', (string) Str::uuid()),
            'origen' => Evidencia::ORIGEN_ARCHIVO,
            'subido_por' => User::factory(),
        ];
    }
}
