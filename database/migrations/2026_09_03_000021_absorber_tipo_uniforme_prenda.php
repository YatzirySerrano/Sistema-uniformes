<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * "Uniforme / Prenda" no es un tipo vigente: un uniforme es un conjunto de
     * activos, no un tipo. Los datos demo antiguos dejaron esa fila. La absorbe
     * en "Prenda" (repunta activos y categorías, funde el pivote de empresas).
     *
     * Forward-only e idempotente. En una BD sin esa fila es un no-op.
     */
    public function up(): void
    {
        $mixto = DB::table('tipos_activo')->where('nombre_normalizado', 'uniforme / prenda')->value('id');

        if ($mixto === null) {
            return;
        }

        $prenda = DB::table('tipos_activo')->where('nombre_normalizado', 'prenda')->value('id');

        if ($prenda === null) {
            DB::table('tipos_activo')->where('id', $mixto)->update([
                'nombre' => 'Prenda',
                'nombre_normalizado' => 'prenda',
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('activos')->where('tipo_activo_id', $mixto)->update(['tipo_activo_id' => $prenda]);
        DB::table('categorias_activo')->where('tipo_activo_id', $mixto)->update(['tipo_activo_id' => $prenda]);

        foreach (DB::table('tipo_activo_empresa')->where('tipo_activo_id', $mixto)->pluck('empresa_id') as $empresaId) {
            DB::table('tipo_activo_empresa')->insertOrIgnore([
                'tipo_activo_id' => $prenda, 'empresa_id' => $empresaId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('tipo_activo_empresa')->where('tipo_activo_id', $mixto)->delete();
        DB::table('tipos_activo')->where('id', $mixto)->delete();
    }

    public function down(): void
    {
        // No reversible: la fila fusionada no se puede reconstruir.
    }
};
