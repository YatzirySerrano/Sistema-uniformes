<?php

namespace Database\Factories;

use App\Models\AcuseRecepcion;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\EntregaUniforme;
use App\Models\Sucursal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcuseRecepcion>
 */
class AcuseRecepcionFactory extends Factory
{
    protected $model = AcuseRecepcion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'folio' => 'ACU-'.now()->format('Y').'-'.fake()->unique()->numerify('######'),
            'entrega_uniforme_id' => EntregaUniforme::factory(),
            'empresa_id' => Empresa::factory(),
            'sucursal_id' => Sucursal::factory(),
            'colaborador_id' => Colaborador::factory(),
            'nombre_firmante_snapshot' => fake()->name(),
            'numero_empleado_snapshot' => (string) fake()->numberBetween(1000, 9999),
            'firmado_en' => now(),
            'ruta_firma' => 'firmas/demo.png',
            'snapshot_entrega' => ['items' => []],
            'hash_documento' => hash('sha256', 'demo'),
            'hash_firma' => hash('sha256', 'firma'),
        ];
    }
}
