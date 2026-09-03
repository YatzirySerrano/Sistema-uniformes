<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Siembra el catálogo base de tipos de activo por empresa. El sistema dejó
     * de ser exclusivo de uniformes: además de "Prenda" se contemplan equipos,
     * dispositivos, accesorios, herramientas, etc.
     *
     * Idempotente: sólo inserta los nombres que aún no existen en la empresa.
     * Respeta la unicidad `(empresa_id, nombre)` y continúa la numeración
     * `TAC-000N` existente.
     */
    private const BASE = [
        'Prenda',
        'Equipo de cómputo',
        'Dispositivo móvil',
        'Electrónico',
        'Accesorio',
        'Herramienta / Equipo',
        'Otro',
    ];

    public function up(): void
    {
        $ahora = now();
        $empresas = DB::table('empresas')->pluck('id');

        foreach ($empresas as $empresaId) {
            $existentes = DB::table('tipos_activo')
                ->where('empresa_id', $empresaId)
                ->pluck('nombre')
                ->all();

            $consecutivo = $this->siguienteConsecutivo($empresaId);

            foreach (self::BASE as $nombre) {
                if (in_array($nombre, $existentes, true)) {
                    continue;
                }

                DB::table('tipos_activo')->insert([
                    'empresa_id' => $empresaId,
                    'nombre' => $nombre,
                    'codigo' => 'TAC-'.str_pad((string) $consecutivo, 4, '0', STR_PAD_LEFT),
                    'activo' => true,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);

                $consecutivo++;
            }
        }
    }

    public function down(): void
    {
        // Sólo se retiran los tipos base sembrados que no tengan activos
        // asociados (para no romper referencias del catálogo).
        DB::table('tipos_activo')
            ->whereIn('nombre', self::BASE)
            ->whereNotIn('id', DB::table('activos')->select('tipo_activo_id')->whereNotNull('tipo_activo_id'))
            ->where('nombre', '!=', 'Prenda')
            ->delete();
    }

    private function siguienteConsecutivo(int $empresaId): int
    {
        $maximo = DB::table('tipos_activo')
            ->where('empresa_id', $empresaId)
            ->where('codigo', 'like', 'TAC-%')
            ->orderByRaw('CAST(SUBSTRING(codigo, 5) AS UNSIGNED) DESC')
            ->value('codigo');

        return $maximo === null ? 1 : ((int) substr($maximo, 4)) + 1;
    }
};
