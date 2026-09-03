<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Migra la categoría de activo de texto libre a catálogo (`categoria_id`).
     *
     * - Añade `activos.categoria_id` (nullable, FK a `categorias_activo`).
     * - Convierte cada valor de texto distinto por empresa en una fila de
     *   `categorias_activo` y enlaza los activos.
     * - Conserva la columna `activos.categoria` como ESPEJO temporal (misma
     *   estrategia que `colaboradores.area`) hasta la reingeniería de Activos;
     *   el controlador la mantiene sincronizada con el nombre de la categoría.
     *
     * Forward-only: no se pierden datos.
     */
    public function up(): void
    {
        Schema::table('activos', function (Blueprint $table): void {
            $table->foreignId('categoria_id')->nullable()->after('categoria')
                ->constrained('categorias_activo')->cascadeOnUpdate()->nullOnDelete();
        });

        $ahora = now();

        $grupos = DB::table('activos')
            ->select('empresa_id', 'categoria')
            ->whereNotNull('categoria')
            ->where('categoria', '!=', '')
            ->distinct()
            ->get();

        foreach ($grupos as $grupo) {
            $nombre = trim($grupo->categoria);

            if ($nombre === '') {
                continue;
            }

            $categoriaId = DB::table('categorias_activo')
                ->where('empresa_id', $grupo->empresa_id)
                ->where('nombre', $nombre)
                ->value('id');

            if ($categoriaId === null) {
                $categoriaId = DB::table('categorias_activo')->insertGetId([
                    'empresa_id' => $grupo->empresa_id,
                    'tipo_activo_id' => null,
                    'nombre' => $nombre,
                    'codigo' => null,
                    'activa' => true,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);
            }

            DB::table('activos')
                ->where('empresa_id', $grupo->empresa_id)
                ->whereRaw('TRIM(categoria) = ?', [$nombre])
                ->update(['categoria_id' => $categoriaId]);
        }
    }

    public function down(): void
    {
        Schema::table('activos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('categoria_id');
        });
    }
};
