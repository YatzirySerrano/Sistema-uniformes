<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Finalidad de la asignación POR RENGLÓN de entrega (`uso_personal` |
     * `redistribucion`, ver `App\Enums\FinalidadCustodia`). Por renglón
     * porque una misma entrega puede llevar la laptop de uso personal y diez
     * camisas para repartir; los componentes de un conjunto ya son renglones
     * propios.
     *
     * Históricos: quedan en NULL ("sin clasificar"). No se infiere nada: no
     * se sabe si la custodia de hoy era para usarse o para repartirse. Siguen
     * contando en la custodia (nada desaparece), pero no se ofrecen para
     * redistribuir sin el permiso de bienes personales hasta que un usuario
     * autorizado los clasifique.
     */
    public function up(): void
    {
        Schema::table('detalles_entrega', function (Blueprint $table): void {
            $table->string('finalidad', 20)->nullable()->after('detalle_origen_id');
        });
    }

    public function down(): void
    {
        Schema::table('detalles_entrega', function (Blueprint $table): void {
            $table->dropColumn('finalidad');
        });
    }
};
