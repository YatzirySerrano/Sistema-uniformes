<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Convierte los valores de texto libre `colaboradores.area` en registros de
     * `areas` por empresa y enlaza `colaboradores.area_id`.
     *
     * Reglas de deduplicación: se colapsan espacios y se comparan sin distinguir
     * mayúsculas/minúsculas; se conserva la primera grafía encontrada. No se
     * "adivinan" fusiones semánticas (p. ej. "RH" y "Recursos Humanos" quedan
     * como áreas distintas). No se pierde información: la columna `area` se
     * mantiene intacta.
     */
    public function up(): void
    {
        $ahora = Carbon::now();

        $empresas = DB::table('colaboradores')
            ->select('empresa_id')
            ->whereNotNull('area')
            ->where('area', '!=', '')
            ->distinct()
            ->pluck('empresa_id');

        foreach ($empresas as $empresaId) {
            $consecutivo = (int) DB::table('areas')->where('empresa_id', $empresaId)->count();

            /** @var array<string, int> $porClave nombre normalizado en minúsculas => area_id */
            $porClave = [];

            $valores = DB::table('colaboradores')
                ->where('empresa_id', $empresaId)
                ->whereNotNull('area')
                ->where('area', '!=', '')
                ->pluck('area');

            foreach ($valores as $valor) {
                $nombre = trim((string) preg_replace('/\s+/u', ' ', (string) $valor));

                if ($nombre === '') {
                    continue;
                }

                $clave = Str::lower($nombre);

                if (! isset($porClave[$clave])) {
                    $existente = DB::table('areas')
                        ->where('empresa_id', $empresaId)
                        ->whereRaw('LOWER(nombre) = ?', [$clave])
                        ->first();

                    if ($existente !== null) {
                        $porClave[$clave] = (int) $existente->id;
                    } else {
                        $consecutivo++;

                        $porClave[$clave] = (int) DB::table('areas')->insertGetId([
                            'empresa_id' => $empresaId,
                            'nombre' => $nombre,
                            'codigo' => 'ARE-'.str_pad((string) $consecutivo, 4, '0', STR_PAD_LEFT),
                            'descripcion' => null,
                            'activa' => true,
                            'created_at' => $ahora,
                            'updated_at' => $ahora,
                        ]);
                    }
                }

                DB::table('colaboradores')
                    ->where('empresa_id', $empresaId)
                    ->where('area', $valor)
                    ->whereNull('area_id')
                    ->update(['area_id' => $porClave[$clave]]);
            }
        }
    }

    public function down(): void
    {
        DB::table('colaboradores')->update(['area_id' => null]);
        DB::table('areas')->delete();
    }
};
