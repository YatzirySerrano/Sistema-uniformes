<?php

namespace Database\Factories;

use App\Models\Empresa;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Empresa>
 */
class EmpresaFactory extends Factory
{
    protected $model = Empresa::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $nombre = fake()->unique()->company();

        return [
            'codigo' => Str::upper(Str::random(6)),
            'nombre_comercial' => $nombre,
            'razon_social' => $nombre.' S.A. de C.V.',
            'rfc' => Str::upper(fake()->bothify('???######???')),
            'telefono' => fake()->phoneNumber(),
            'correo' => fake()->companyEmail(),
            'direccion' => fake()->address(),
            'color_principal' => fake()->hexColor(),
            'color_secundario' => fake()->hexColor(),
            'color_acento' => fake()->hexColor(),
            'activa' => true,
        ];
    }
}
