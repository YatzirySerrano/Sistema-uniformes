<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Historia append-only de movimientos de inventario. `sucursal_id` es
     * procedencia/contexto histórico (nullable), nunca dimensión de stock:
     * la llave operativa es `empresa + almacén + activo + talla`.
     * `unidad_activo_id` identifica el movimiento de una unidad de
     * seguimiento individual (no toca `saldos_inventario`, `talla_id` va
     * nulo, `cantidad = 1`).
     */
    public function up(): void
    {
        Schema::create('movimientos_inventario', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('almacen_id')->nullable()->constrained('almacenes')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('sucursal_id')->nullable()->constrained('sucursales')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('activo_id')->constrained('activos')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('talla_id')->nullable()->constrained('tallas')->nullOnDelete();
            $table->foreignId('unidad_activo_id')->nullable()->constrained('unidades_activo')->nullOnDelete();
            $table->string('tipo', 30);
            $table->string('direccion', 10);
            $table->unsignedInteger('cantidad');
            $table->integer('existencia_anterior');
            $table->integer('existencia_resultante');
            $table->string('referencia_tipo')->nullable();
            $table->unsignedBigInteger('referencia_id')->nullable();
            $table->string('motivo')->nullable();
            $table->text('notas')->nullable();
            $table->foreignId('realizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ocurrido_en');
            $table->timestamps();

            $table->index(['empresa_id', 'almacen_id', 'activo_id', 'talla_id'], 'movimientos_inv_almacen_idx');
            $table->index(['referencia_tipo', 'referencia_id']);
            $table->index('tipo');
            $table->index('ocurrido_en');
            $table->index('unidad_activo_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_inventario');
    }
};
