<?php

namespace Database\Seeders;

use App\Models\Activo;
use App\Models\Empresa;
use App\Models\Talla;
use App\Models\TipoActivo;
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

            // Tipos de activo base (catálogo extensible por el administrador).
            $tipos = collect([
                'Uniforme / Prenda', 'Equipo de cómputo', 'Dispositivo móvil', 'Accesorio', 'Otro',
            ])->mapWithKeys(fn (string $nombre, int $i): array => [$nombre => TipoActivo::query()->updateOrCreate(
                ['empresa_id' => $empresa->id, 'nombre' => $nombre],
                ['codigo' => 'TAC-'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT), 'activo' => true],
            )->id]);

            $activos = [
                ['nombre' => 'Camisa Operativa', 'categoria' => 'Superior', 'codigo' => 'CAM-001', 'tipo' => 'Uniforme / Prenda', 'control' => 'cantidad', 'tallas' => $tallasLetraModelos],
                ['nombre' => 'Pantalón Operativo', 'categoria' => 'Inferior', 'codigo' => 'PAN-001', 'tipo' => 'Uniforme / Prenda', 'control' => 'cantidad', 'tallas' => $tallasNumModelos],
                ['nombre' => 'Chamarra Institucional', 'categoria' => 'Abrigo', 'codigo' => 'CHA-001', 'tipo' => 'Uniforme / Prenda', 'control' => 'cantidad', 'tallas' => $tallasLetraModelos],
                ['nombre' => 'Calzado de Seguridad', 'categoria' => 'Calzado', 'codigo' => 'CAL-001', 'tipo' => 'Uniforme / Prenda', 'control' => 'cantidad', 'tallas' => $tallasNumModelos],
                ['nombre' => 'Gorra', 'categoria' => 'Accesorio', 'codigo' => 'GOR-001', 'tipo' => 'Accesorio', 'control' => 'cantidad', 'tallas' => $tallasLetraModelos],
                ['nombre' => 'Laptop de Dotación', 'categoria' => 'Cómputo', 'codigo' => 'LAP-001', 'tipo' => 'Equipo de cómputo', 'control' => 'serializado', 'tallas' => collect()],
            ];

            foreach ($activos as $a) {
                $activo = Activo::query()->updateOrCreate(
                    ['empresa_id' => $empresa->id, 'codigo' => $a['codigo']],
                    [
                        'tipo_activo_id' => $tipos[$a['tipo']],
                        'nombre' => $a['nombre'],
                        'categoria' => $a['categoria'],
                        'tipo_control' => $a['control'],
                        'descripcion' => 'Activo de dotación institucional.',
                        'activo' => true,
                    ],
                );
                $activo->tallas()->sync($a['tallas']);
            }
        }
    }
}
