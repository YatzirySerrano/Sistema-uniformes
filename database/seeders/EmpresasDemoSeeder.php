<?php

namespace Database\Seeders;

use App\Models\Empresa;
use App\Models\Sucursal;
use Illuminate\Database\Seeder;

class EmpresasDemoSeeder extends Seeder
{
    public function run(): void
    {
        $empresas = [
            [
                'codigo' => 'EMP-A', 'nombre_comercial' => 'Industrias del Valle',
                'razon_social' => 'Industrias del Valle S.A. de C.V.',
                'color_principal' => '#2563eb', 'color_secundario' => '#1e40af', 'color_acento' => '#f59e0b',
                'sucursales' => ['Matriz Cuernavaca', 'Planta Jiutepec', 'Bodega Temixco'],
            ],
            [
                'codigo' => 'EMP-B', 'nombre_comercial' => 'Alimentos Sierra Verde',
                'razon_social' => 'Alimentos Sierra Verde S. de R.L.',
                'color_principal' => '#16a34a', 'color_secundario' => '#15803d', 'color_acento' => '#ca8a04',
                'sucursales' => ['Centro de Distribución', 'Sucursal Norte'],
            ],
            [
                'codigo' => 'EMP-C', 'nombre_comercial' => 'Logística Ferro',
                'razon_social' => 'Logística Ferro S.A.P.I. de C.V.',
                'color_principal' => '#9f1239', 'color_secundario' => '#881337', 'color_acento' => '#0ea5e9',
                'sucursales' => ['Terminal Sur', 'Terminal Poniente', 'Patio Oriente', 'Oficinas Corporativo'],
            ],
        ];

        foreach ($empresas as $datos) {
            $sucursales = $datos['sucursales'];
            unset($datos['sucursales']);

            $empresa = Empresa::query()->updateOrCreate(['codigo' => $datos['codigo']], $datos + ['activa' => true]);

            foreach ($sucursales as $i => $nombre) {
                Sucursal::query()->updateOrCreate(
                    ['empresa_id' => $empresa->id, 'codigo' => 'S'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT)],
                    ['nombre' => $nombre, 'activa' => true, 'direccion' => 'Domicilio conocido s/n', 'telefono' => '777-000-00'.$i],
                );
            }
        }
    }
}
