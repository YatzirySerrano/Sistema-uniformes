<?php

use App\Soporte\NormalizadorNombre;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unicidad normalizada del nombre en los catálogos de activo por empresa.
     *
     * Añade `nombre_normalizado` (minúsculas + espacios colapsados) a
     * `tipos_activo` y `categorias_activo`, la rellena a partir de `nombre`, y
     * cambia el índice único `(empresa_id, nombre)` por
     * `(empresa_id, nombre_normalizado)`. Así "Prenda", " prenda " y "PRENDA"
     * dejan de poder coexistir dentro de la misma empresa (empresas distintas sí
     * pueden repetir nombre). El modelo mantiene la columna sincronizada en cada
     * `save()` (`App\Models\Concerns\NombreNormalizado`).
     *
     * Forward-only. El índice `(empresa_id, nombre)` previo ya impedía nombres
     * exactamente iguales; si en producción existieran filas que sólo difieren en
     * mayúsculas/espacios, hay que unificarlas antes (no aplica a los datos demo).
     */
    private const TABLAS = ['tipos_activo', 'categorias_activo'];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla) {
            if (! Schema::hasColumn($tabla, 'nombre_normalizado')) {
                Schema::table($tabla, function (Blueprint $table): void {
                    $table->string('nombre_normalizado')->default('')->after('nombre');
                });
            }

            DB::table($tabla)->orderBy('id')->chunkById(500, function ($filas) use ($tabla): void {
                foreach ($filas as $fila) {
                    DB::table($tabla)
                        ->where('id', $fila->id)
                        ->update(['nombre_normalizado' => NormalizadorNombre::catalogo($fila->nombre)]);
                }
            });

            // El nuevo índice se crea ANTES de borrar el viejo: el índice
            // `(empresa_id, nombre)` respalda la FK de `empresa_id` en MariaDB y
            // no puede soltarse hasta que otro índice cubra esa columna.
            Schema::table($tabla, function (Blueprint $table): void {
                $table->unique(['empresa_id', 'nombre_normalizado']);
            });

            Schema::table($tabla, function (Blueprint $table) use ($tabla): void {
                $table->dropUnique($tabla.'_empresa_id_nombre_unique');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, function (Blueprint $table): void {
                $table->unique(['empresa_id', 'nombre']);
            });

            Schema::table($tabla, function (Blueprint $table): void {
                $table->dropUnique(['empresa_id', 'nombre_normalizado']);
            });

            Schema::table($tabla, function (Blueprint $table): void {
                $table->dropColumn('nombre_normalizado');
            });
        }
    }
};
