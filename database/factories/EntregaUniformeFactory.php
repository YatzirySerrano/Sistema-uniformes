<?php

namespace Database\Factories;

use App\Enums\EstadoEntrega;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\EntregaUniforme;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EntregaUniforme>
 */
class EntregaUniformeFactory extends Factory
{
    protected $model = EntregaUniforme::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'folio' => 'ENT-'.now()->format('Y').'-'.fake()->unique()->numerify('######'),
            'empresa_id' => Empresa::factory(),
            'sucursal_id' => Sucursal::factory(),
            'colaborador_id' => Colaborador::factory(),
            'encargado_id' => User::factory(),
            'estado' => EstadoEntrega::PendienteFirma,
            'fecha_entrega' => now()->toDateString(),
        ];
    }
}
