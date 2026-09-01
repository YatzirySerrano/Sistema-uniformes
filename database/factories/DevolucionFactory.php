<?php

namespace Database\Factories;

use App\Models\Colaborador;
use App\Models\Devolucion;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Devolucion>
 */
class DevolucionFactory extends Factory
{
    protected $model = Devolucion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'folio' => 'DEV-'.now()->format('Y').'-'.fake()->unique()->numerify('######'),
            'empresa_id' => Empresa::factory(),
            'sucursal_id' => Sucursal::factory(),
            'colaborador_id' => Colaborador::factory(),
            'registrada_por' => User::factory(),
            'fecha' => now()->toDateString(),
        ];
    }
}
