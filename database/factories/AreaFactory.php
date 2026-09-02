<?php

namespace Database\Factories;

use App\Models\Area;
use App\Models\Empresa;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Area>
 */
class AreaFactory extends Factory
{
    protected $model = Area::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $nombre = fake()->unique()->randomElement([
            'Seguridad', 'Supervisión', 'Recursos Humanos', 'Administración',
            'Contabilidad', 'Sistemas', 'Operaciones', 'Logística', 'Mantenimiento',
        ]).' '.Str::upper(Str::random(3));

        return [
            'empresa_id' => Empresa::factory(),
            'nombre' => $nombre,
            'codigo' => 'ARE-'.fake()->unique()->numberBetween(1000, 9999),
            'descripcion' => fake()->boolean(40) ? fake()->sentence() : null,
            'activa' => true,
        ];
    }

    public function inactiva(): static
    {
        return $this->state(fn (): array => ['activa' => false]);
    }
}
