<?php

namespace Database\Seeders;

use App\Models\Almacen;
use App\Models\Colaborador;
use App\Models\Empresa;
use Illuminate\Database\Seeder;

/**
 * Almacenes de demostración. Cada empresa tiene un almacén propio y, además,
 * todas comparten un "Almacén Central Morelos" (N:M `almacen_empresa`): el
 * inventario se mantiene separado por empresa dentro de ese almacén.
 */
class AlmacenesDemoSeeder extends Seeder
{
    public function run(): void
    {
        $empresas = Empresa::query()->orderBy('codigo')->get();

        if ($empresas->isEmpty()) {
            return;
        }

        $indice = 1;

        // Un almacén propio por empresa.
        foreach ($empresas as $empresa) {
            $responsable = Colaborador::query()
                ->where('empresa_id', $empresa->id)
                ->where('activo', true)
                ->inRandomOrder()
                ->first();

            $almacen = Almacen::query()->updateOrCreate(
                ['codigo' => 'ALM-'.str_pad((string) $indice, 4, '0', STR_PAD_LEFT)],
                [
                    'nombre' => 'Almacén '.$empresa->nombre_comercial,
                    'descripcion' => 'Almacén de dotación de '.$empresa->nombre_comercial.'.',
                    'direccion' => fake()->streetAddress(),
                    'telefono' => fake()->numerify('##########'),
                    'correo' => null,
                    'responsable_colaborador_id' => $responsable?->id,
                    'activo' => true,
                ],
            );

            $almacen->empresas()->syncWithoutDetaching([$empresa->id]);
            $indice++;
        }

        // Almacén compartido por todas las empresas demo.
        $central = Almacen::query()->updateOrCreate(
            ['codigo' => 'ALM-'.str_pad((string) $indice, 4, '0', STR_PAD_LEFT)],
            [
                'nombre' => 'Almacén Central Morelos',
                'descripcion' => 'Almacén compartido: abastece a varias razones sociales; el inventario se mantiene separado por empresa.',
                'direccion' => fake()->streetAddress(),
                'telefono' => fake()->numerify('##########'),
                'correo' => null,
                'responsable_colaborador_id' => null,
                'activo' => true,
            ],
        );

        $central->empresas()->syncWithoutDetaching($empresas->pluck('id')->all());
    }
}
