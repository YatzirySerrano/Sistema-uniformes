<?php

namespace Database\Seeders;

use App\Models\Empresa;
use App\Models\Prenda;
use App\Models\Talla;
use Illuminate\Database\Seeder;

class CatalogosDemoSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Empresa::all() as $empresa) {
            $tallasLetra = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];
            $tallasNum = ['28', '30', '32', '34', '36'];

            $tallas = collect();
            foreach ([...$tallasLetra, ...$tallasNum] as $i => $valor) {
                $tallas->push(Talla::query()->updateOrCreate(
                    ['empresa_id' => $empresa->id, 'valor' => $valor],
                    ['orden' => $i, 'activa' => true],
                ));
            }

            $tallasLetraModelos = $tallas->whereIn('valor', $tallasLetra)->pluck('id');
            $tallasNumModelos = $tallas->whereIn('valor', $tallasNum)->pluck('id');

            $prendas = [
                ['nombre' => 'Camisa Operativa', 'categoria' => 'Superior', 'codigo' => 'CAM-001', 'tallas' => $tallasLetraModelos],
                ['nombre' => 'Pantalón Operativo', 'categoria' => 'Inferior', 'codigo' => 'PAN-001', 'tallas' => $tallasNumModelos],
                ['nombre' => 'Chamarra Institucional', 'categoria' => 'Abrigo', 'codigo' => 'CHA-001', 'tallas' => $tallasLetraModelos],
                ['nombre' => 'Calzado de Seguridad', 'categoria' => 'Calzado', 'codigo' => 'CAL-001', 'tallas' => $tallasNumModelos],
                ['nombre' => 'Gorra', 'categoria' => 'Accesorio', 'codigo' => 'GOR-001', 'tallas' => $tallasLetraModelos],
            ];

            foreach ($prendas as $p) {
                $prenda = Prenda::query()->updateOrCreate(
                    ['empresa_id' => $empresa->id, 'codigo_interno' => $p['codigo']],
                    ['nombre' => $p['nombre'], 'categoria' => $p['categoria'], 'descripcion' => 'Prenda de dotación institucional.', 'activa' => true],
                );
                $prenda->tallas()->sync($p['tallas']);
            }
        }
    }
}
