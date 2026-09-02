<?php

namespace Database\Seeders;

use App\Models\Almacen;
use App\Models\Colaborador;
use App\Models\Empresa;
use Illuminate\Database\Seeder;

class AlmacenesDemoSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Empresa::with('sucursales')->get() as $empresa) {
            $sucursales = $empresa->sucursales;

            if ($sucursales->isEmpty()) {
                continue;
            }

            $mitad = (int) ceil($sucursales->count() / 2);
            $grupos = [
                'Almacén Central' => $sucursales->take($mitad),
                'Almacén Secundario' => $sucursales->slice($mitad),
            ];

            $indice = 1;
            foreach ($grupos as $nombre => $abastece) {
                if ($abastece->isEmpty()) {
                    continue;
                }

                $responsable = Colaborador::query()
                    ->where('empresa_id', $empresa->id)
                    ->where('activo', true)
                    ->inRandomOrder()
                    ->first();

                $almacen = Almacen::query()->updateOrCreate(
                    ['empresa_id' => $empresa->id, 'codigo' => 'ALM-'.str_pad((string) $indice, 4, '0', STR_PAD_LEFT)],
                    [
                        'nombre' => $nombre,
                        'descripcion' => 'Almacén de dotación de '.$empresa->nombre_comercial.'.',
                        'direccion' => fake()->streetAddress(),
                        'telefono' => fake()->numerify('##########'),
                        'correo' => null,
                        'responsable_colaborador_id' => $responsable?->id,
                        'activo' => true,
                    ],
                );

                $almacen->sucursales()->sync($abastece->pluck('id'));
                $indice++;
            }
        }
    }
}
