<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Corrección conceptual: una PRENDA es un activo individual; un UNIFORME es
     * un conjunto de prendas. El catálogo `tipos_activo` había quedado con un
     * tipo mixto "Uniforme / Prenda"; aquí se normaliza a "Prenda" sin perder
     * activos.
     *
     * Por cada empresa:
     * - Si NO existe ya un tipo "Prenda": se renombra "Uniforme / Prenda" a
     *   "Prenda".
     * - Si YA existe "Prenda": se reasignan los activos del tipo mixto a
     *   "Prenda" y se elimina el tipo mixto (ningún activo lo referencia tras la
     *   reasignación; `tipos_activo` no se guarda en snapshots).
     *
     * Forward-only y sin pérdida de datos: no se tocan inventarios, entregas ni
     * históricos.
     */
    public function up(): void
    {
        $mixtos = DB::table('tipos_activo')->where('nombre', 'Uniforme / Prenda')->get();

        foreach ($mixtos as $mixto) {
            $prendaExistente = DB::table('tipos_activo')
                ->where('empresa_id', $mixto->empresa_id)
                ->where('nombre', 'Prenda')
                ->value('id');

            if ($prendaExistente === null) {
                DB::table('tipos_activo')->where('id', $mixto->id)->update([
                    'nombre' => 'Prenda',
                    'updated_at' => now(),
                ]);

                continue;
            }

            DB::table('activos')
                ->where('tipo_activo_id', $mixto->id)
                ->update(['tipo_activo_id' => $prendaExistente]);

            DB::table('tipos_activo')->where('id', $mixto->id)->delete();
        }
    }

    public function down(): void
    {
        // Renombrado inverso best-effort: "Prenda" vuelve a "Uniforme / Prenda"
        // sólo si no existe ya ese nombre en la empresa.
        $prendas = DB::table('tipos_activo')->where('nombre', 'Prenda')->get();

        foreach ($prendas as $prenda) {
            $existeMixto = DB::table('tipos_activo')
                ->where('empresa_id', $prenda->empresa_id)
                ->where('nombre', 'Uniforme / Prenda')
                ->exists();

            if (! $existeMixto) {
                DB::table('tipos_activo')->where('id', $prenda->id)->update([
                    'nombre' => 'Uniforme / Prenda',
                    'updated_at' => now(),
                ]);
            }
        }
    }
};
