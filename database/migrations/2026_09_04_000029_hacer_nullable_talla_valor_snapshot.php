<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `detalles_entrega.talla_valor_snapshot` seguía NOT NULL desde el
     * esquema original, previo a que `talla_id` se volviera nullable
     * (`..._000020_catalogos_compartidos`, "sin variante" = `talla_id = NULL`)
     * y a que un renglón pudiera ser una unidad de seguimiento individual
     * (`unidad_activo_id`, sin talla). Ambos casos insertan `talla_valor_snapshot
     * = null`; la columna debe permitirlo. Forward-only.
     */
    public function up(): void
    {
        Schema::table('detalles_entrega', function (Blueprint $table): void {
            $table->string('talla_valor_snapshot', 30)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('detalles_entrega', function (Blueprint $table): void {
            $table->string('talla_valor_snapshot', 30)->nullable(false)->change();
        });
    }
};
