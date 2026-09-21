<?php

use App\Soporte\NormalizadorNombre;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El importador de colaboradores (`ServicioImportacionColaboradores`)
     * necesita resolver "Recursos Humanos" / "recursos humanos" /
     * "RECURSOS HUMANOS" a la MISMA área dentro de una empresa — mismo
     * criterio que `App\Models\Concerns\NombreNormalizado`, ya usado en los
     * catálogos globales de Activo (tipos/categorías/tallas). A diferencia
     * de esos catálogos, Área SÍ pertenece a una empresa: el índice único es
     * (empresa_id, nombre_normalizado), no global — "Compras" puede existir
     * en dos empresas distintas sin chocar.
     *
     * Backfill en PHP (no en SQL) porque el colapso de espacios múltiples de
     * `NormalizadorNombre::catalogo()` no es trivialmente portable entre
     * MySQL/MariaDB y SQLite: usa exactamente la misma función que usará el
     * resolver en runtime, así el backfill y la lógica nueva nunca pueden
     * divergir. Verificado antes de escribir esta migración: la BD actual no
     * tiene ninguna área duplicada por mayúsculas/espacios dentro de la
     * misma empresa, así que el índice único es seguro de agregar.
     */
    public function up(): void
    {
        Schema::table('areas', function (Blueprint $table): void {
            $table->string('nombre_normalizado')->default('')->after('nombre');
        });

        DB::table('areas')->select('id', 'nombre')->get()->each(function (object $area): void {
            DB::table('areas')->where('id', $area->id)->update([
                'nombre_normalizado' => NormalizadorNombre::catalogo($area->nombre),
            ]);
        });

        Schema::table('areas', function (Blueprint $table): void {
            $table->unique(['empresa_id', 'nombre_normalizado']);
        });
    }

    public function down(): void
    {
        Schema::table('areas', function (Blueprint $table): void {
            $table->dropUnique(['empresa_id', 'nombre_normalizado']);
            $table->dropColumn('nombre_normalizado');
        });
    }
};
