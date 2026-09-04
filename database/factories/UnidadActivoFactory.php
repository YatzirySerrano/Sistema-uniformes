<?php

namespace Database\Factories;

use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoUnidadActivo;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Empresa;
use App\Models\UnidadActivo;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UnidadActivo>
 */
class UnidadActivoFactory extends Factory
{
    protected $model = UnidadActivo::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'activo_id' => Activo::factory(),
            'almacen_id' => Almacen::factory(),
            'codigo' => 'TST-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'public_token' => (string) Str::uuid(),
            'estado' => EstadoUnidadActivo::EnAlmacen,
            'condicion' => CondicionUnidadActivo::Funcionando,
        ];
    }

    public function asignada(): static
    {
        return $this->state(fn (): array => ['estado' => EstadoUnidadActivo::Asignada]);
    }

    public function baja(): static
    {
        return $this->state(fn (): array => [
            'estado' => EstadoUnidadActivo::Baja,
            'dado_de_baja_en' => now(),
            'motivo_baja' => 'Baja de prueba',
        ]);
    }

    public function conCondicion(CondicionUnidadActivo $condicion): static
    {
        return $this->state(fn (): array => ['condicion' => $condicion]);
    }
}
