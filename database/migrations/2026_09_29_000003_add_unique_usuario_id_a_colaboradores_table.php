<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Una cuenta de acceso representa como máximo a UNA ficha de colaborador:
     * sin esto, "mi custodia" (redistribución) sería ambigua. `usuario_id`
     * sigue siendo nullable (varias fichas sin cuenta son válidas: los NULL no
     * chocan en un índice único).
     *
     * Si ya hubiera una cuenta ligada a varias fichas, NO se desvincula nada en
     * silencio: la migración se detiene y dice cuáles corregir primero.
     */
    public function up(): void
    {
        $duplicadas = DB::table('colaboradores')
            ->whereNotNull('usuario_id')
            ->groupBy('usuario_id')
            ->havingRaw('count(*) > 1')
            ->pluck('usuario_id');

        if ($duplicadas->isNotEmpty()) {
            throw new RuntimeException(
                'Hay cuentas ligadas a más de un colaborador (usuario_id: '.$duplicadas->implode(', ')
                .'). Deja una sola ficha por cuenta y vuelve a ejecutar la migración.'
            );
        }

        Schema::table('colaboradores', function (Blueprint $table): void {
            $table->unique('usuario_id');
        });
    }

    public function down(): void
    {
        Schema::table('colaboradores', function (Blueprint $table): void {
            $table->dropUnique(['usuario_id']);
        });
    }
};
