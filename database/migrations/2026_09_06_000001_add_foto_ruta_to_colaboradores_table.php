<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `foto_ruta` guarda la ruta relativa en el disco privado `local`
     * (`colaboradores/{empresa_id}/{uuid}.ext`); se sirve siempre por
     * `ColaboradorController::foto()` con Policy, nunca como URL pública.
     */
    public function up(): void
    {
        Schema::table('colaboradores', function (Blueprint $table): void {
            $table->string('foto_ruta')->nullable()->after('correo');
        });
    }

    public function down(): void
    {
        Schema::table('colaboradores', function (Blueprint $table): void {
            $table->dropColumn('foto_ruta');
        });
    }
};
