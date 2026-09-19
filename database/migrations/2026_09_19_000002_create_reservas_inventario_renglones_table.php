<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Líneas de una reserva (`reservas_inventario`). Cada fila representa
     * exactamente UNA de estas cuatro formas, sin ambigüedad (columnas
     * estructuradas, nunca JSON opaco):
     *
     *  - ENTREGA cantidad: activo_id + talla_id (nullable) + cantidad.
     *    (empresa/almacén ya los fija la cabecera).
     *  - ENTREGA unidad: unidad_activo_id.
     *  - DEVOLUCIÓN cantidad: detalle_entrega_id + cantidad (custodia
     *    pendiente que se apartan, no stock de almacén).
     *  - DEVOLUCIÓN unidad: unidad_activo_id (+ detalle_entrega_id de
     *    referencia, para trazabilidad/auditoría).
     */
    public function up(): void
    {
        Schema::create('reservas_inventario_renglones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reserva_id')->constrained('reservas_inventario')->cascadeOnDelete();
            $table->foreignId('activo_id')->nullable()->constrained('activos')->cascadeOnDelete();
            $table->foreignId('talla_id')->nullable()->constrained('tallas')->nullOnDelete();
            $table->unsignedInteger('cantidad')->nullable();
            $table->foreignId('unidad_activo_id')->nullable()->constrained('unidades_activo')->cascadeOnDelete();
            $table->foreignId('detalle_entrega_id')->nullable()->constrained('detalles_entrega')->cascadeOnDelete();
            $table->timestamps();

            $table->index('reserva_id', 'reservas_inv_renglones_reserva_idx');
            $table->index(['activo_id', 'talla_id'], 'reservas_inv_renglones_activo_talla_idx');
            $table->index('unidad_activo_id', 'reservas_inv_renglones_unidad_idx');
            $table->index('detalle_entrega_id', 'reservas_inv_renglones_detalle_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservas_inventario_renglones');
    }
};
