<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Renglón de una entrega. `talla_id` es nullable ("sin variante" o
     * renglón de una unidad individual). `unidad_activo_id` identifica el
     * renglón de una unidad de seguimiento individual (`cantidad` se
     * conserva en 1). `conjunto_id` + `conjunto_nombre_snapshot` son
     * procedencia informativa: el renglón sigue siendo un componente real e
     * independiente para efectos de stock; agregar un activo suelto a una
     * entrega con conjunto NO modifica la definición del conjunto.
     */
    public function up(): void
    {
        Schema::create('detalles_entrega', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entrega_uniforme_id')->constrained('entregas_uniformes')->cascadeOnDelete();
            $table->foreignId('activo_id')->constrained('activos')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('talla_id')->nullable()->constrained('tallas')->nullOnDelete();
            $table->foreignId('unidad_activo_id')->nullable()->constrained('unidades_activo')->nullOnDelete();
            $table->foreignId('conjunto_id')->nullable()->constrained('conjuntos')->nullOnDelete();
            $table->string('conjunto_nombre_snapshot')->nullable();
            $table->unsignedInteger('cantidad');
            $table->string('activo_nombre_snapshot');
            $table->string('talla_valor_snapshot', 30)->nullable();
            $table->timestamps();

            $table->index('entrega_uniforme_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detalles_entrega');
    }
};
