<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Todos los activos existentes provienen del catálogo de uniformes: se crea
     * (o reutiliza) el tipo "Uniforme / Prenda" por empresa y se asigna a los
     * activos que aún no tengan tipo. El control (`tipo_control`) ya quedó en
     * 'cantidad' por defecto, que es lo correcto para uniformes.
     */
    public function up(): void
    {
        $ahora = Carbon::now();

        $empresas = DB::table('activos')->select('empresa_id')->distinct()->pluck('empresa_id');

        foreach ($empresas as $empresaId) {
            $tipoId = DB::table('tipos_activo')
                ->where('empresa_id', $empresaId)
                ->where('nombre', 'Uniforme / Prenda')
                ->value('id');

            if ($tipoId === null) {
                $tipoId = DB::table('tipos_activo')->insertGetId([
                    'empresa_id' => $empresaId,
                    'nombre' => 'Uniforme / Prenda',
                    'codigo' => 'TAC-0001',
                    'activo' => true,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);
            }

            DB::table('activos')
                ->where('empresa_id', $empresaId)
                ->whereNull('tipo_activo_id')
                ->update(['tipo_activo_id' => $tipoId]);
        }
    }

    public function down(): void
    {
        DB::table('activos')->update(['tipo_activo_id' => null]);
        DB::table('tipos_activo')->where('nombre', 'Uniforme / Prenda')->delete();
    }
};
