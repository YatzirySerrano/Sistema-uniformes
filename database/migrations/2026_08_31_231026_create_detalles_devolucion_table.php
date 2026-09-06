<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `detalle_entrega_id` referencia el renglón de origen: permite acumular
     * "cuánto se ha devuelto ya" de ESE renglón concreto y rechazar devolver
     * más de lo pendiente. `condicion` (CondicionDevolucion, renglones por
     * cantidad) y `condicion_unidad` (CondicionUnidadActivo, unidades
     * individuales) son columnas separadas porque son dos enums con valores
     * distintos: mezclarlos rompería el cast tipado de Eloquent.
     */
    public function up(): void
    {
        Schema::create('detalles_devolucion', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('devolucion_id')->constrained('devoluciones')->cascadeOnDelete();
            $table->foreignId('activo_id')->constrained('activos')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('talla_id')->nullable()->constrained('tallas')->nullOnDelete();
            $table->foreignId('unidad_activo_id')->nullable()->constrained('unidades_activo')->nullOnDelete();
            $table->foreignId('detalle_entrega_id')->nullable()->constrained('detalles_entrega')->nullOnDelete();
            $table->unsignedInteger('cantidad');
            $table->string('condicion', 20)->default('reutilizable');
            $table->string('condicion_unidad', 20)->nullable();
            $table->boolean('reingresa_inventario')->default(true);
            $table->timestamps();

            $table->index('devolucion_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detalles_devolucion');
    }
};
