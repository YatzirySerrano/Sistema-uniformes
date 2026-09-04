<?php

namespace Database\Seeders;

use App\Models\Activo;
use App\Models\CategoriaActivo;
use App\Models\Empresa;
use App\Models\Talla;
use App\Models\TipoActivo;
use Illuminate\Database\Seeder;

/**
 * Catálogos DEMO. Tipos, categorías y variantes son GLOBALES de plataforma:
 * una sola vez, visibles para todas las empresas por igual. Los activos siguen
 * perteneciendo a una empresa; su stock es independiente por empresa.
 */
class CatalogosDemoSeeder extends Seeder
{
    public function run(): void
    {
        $empresas = Empresa::all();

        // --- Variantes / tallas globales ---
        $valores = ['XS', 'S', 'M', 'L', 'XL', 'XXL', '28', '30', '32', '34', '36'];
        $tallas = collect();
        foreach ($valores as $i => $valor) {
            $talla = Talla::query()->updateOrCreate(
                ['valor' => $valor],
                ['orden' => $i, 'activa' => true],
            );
            $tallas->put($valor, $talla->id);
        }

        $tallasLetra = $tallas->only(['XS', 'S', 'M', 'L', 'XL', 'XXL'])->values();
        $tallasNum = $tallas->only(['28', '30', '32', '34', '36'])->values();

        // --- Tipos de activo globales (sin "Uniforme / Prenda": es "Prenda") ---
        $tipos = collect([
            'Prenda', 'Equipo de cómputo', 'Dispositivo móvil', 'Accesorio', 'Otro',
        ])->mapWithKeys(function (string $nombre, int $i): array {
            $tipo = TipoActivo::query()->updateOrCreate(
                ['nombre' => $nombre],
                ['codigo' => 'TAC-'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT), 'activo' => true],
            );

            return [$nombre => $tipo->id];
        });

        // --- Categorías globales ---
        $categorias = collect([
            'Superior' => 'Prenda', 'Inferior' => 'Prenda', 'Abrigo' => 'Prenda',
            'Calzado' => 'Prenda', 'Accesorio' => 'Accesorio', 'Cómputo' => 'Equipo de cómputo',
        ])->mapWithKeys(function (string $tipo, string $nombre) use ($tipos): array {
            $categoria = CategoriaActivo::query()->updateOrCreate(
                ['nombre' => $nombre],
                ['tipo_activo_id' => $tipos[$tipo], 'activa' => true],
            );

            return [$nombre => $categoria->id];
        });

        // --- Activos por empresa (usan el catálogo compartido) ---
        $plantilla = [
            ['nombre' => 'Camisa Operativa', 'cat' => 'Superior', 'codigo' => 'CAM-001', 'tipo' => 'Prenda', 'control' => 'cantidad', 'tallas' => $tallasLetra],
            ['nombre' => 'Pantalón Operativo', 'cat' => 'Inferior', 'codigo' => 'PAN-001', 'tipo' => 'Prenda', 'control' => 'cantidad', 'tallas' => $tallasNum],
            ['nombre' => 'Chamarra Institucional', 'cat' => 'Abrigo', 'codigo' => 'CHA-001', 'tipo' => 'Prenda', 'control' => 'cantidad', 'tallas' => $tallasLetra],
            ['nombre' => 'Calzado de Seguridad', 'cat' => 'Calzado', 'codigo' => 'CAL-001', 'tipo' => 'Prenda', 'control' => 'cantidad', 'tallas' => $tallasNum],
            ['nombre' => 'Gorra', 'cat' => 'Accesorio', 'codigo' => 'GOR-001', 'tipo' => 'Accesorio', 'control' => 'cantidad', 'tallas' => collect()],
            ['nombre' => 'Laptop de Dotación', 'cat' => 'Cómputo', 'codigo' => 'LAP-001', 'tipo' => 'Equipo de cómputo', 'control' => 'individual', 'tallas' => collect()],
        ];

        foreach ($empresas as $empresa) {
            foreach ($plantilla as $a) {
                $activo = Activo::query()->updateOrCreate(
                    ['empresa_id' => $empresa->id, 'codigo' => $a['codigo']],
                    [
                        'tipo_activo_id' => $tipos[$a['tipo']],
                        'categoria_id' => $categorias[$a['cat']],
                        'categoria' => $a['cat'],
                        'nombre' => $a['nombre'],
                        'tipo_control' => $a['control'],
                        'descripcion' => 'Activo de dotación institucional.',
                        'activo' => true,
                    ],
                );
                $activo->tallas()->sync($a['tallas']->all());
            }
        }
    }
}
