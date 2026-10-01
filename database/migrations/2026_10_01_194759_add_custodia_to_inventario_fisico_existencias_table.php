<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La ronda integral también comprueba las piezas POR CANTIDAD que están
     * bajo custodia de colaboradores. Un renglón de custodia no tiene almacén:
     * se identifica por custodio + activo + variante + finalidad, y nada de lo
     * que ya existía en la tabla permitía guardar al custodio ni la finalidad
     * congelados al iniciar la ronda.
     *
     * Aditiva: los renglones existentes quedan con `colaborador_id` NULL (=
     * renglón de almacén, igual que hasta hoy). `finalidad` sólo tiene sentido
     * con custodio; NULL en un renglón de custodia = "Sin clasificar".
     */
    public function up(): void
    {
        Schema::table('inventario_fisico_existencias', function (Blueprint $table): void {
            $table->foreignId('colaborador_id')->nullable()->after('almacen_id')
                ->constrained('colaboradores')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('finalidad', 20)->nullable()->after('colaborador_id');
        });
    }

    public function down(): void
    {
        Schema::table('inventario_fisico_existencias', function (Blueprint $table): void {
            $table->dropColumn('finalidad');
            $table->dropConstrainedForeignId('colaborador_id');
        });
    }
};
