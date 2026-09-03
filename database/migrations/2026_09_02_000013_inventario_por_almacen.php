<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Arquitectura definitiva del inventario: el ORIGEN físico del stock es el
     * ALMACÉN, no la sucursal.
     *
     *   ALMACÉN + ACTIVO + VARIANTE = STOCK
     *
     * La sucursal pasa a ser únicamente el destino/contexto del colaborador.
     *
     * Cambios (aditivos y forward-only; los datos legacy se conservan y se
     * migran en la siguiente migración / con el asistente):
     * - `saldos_inventario` y `movimientos_inventario`: nueva FK `almacen_id`
     *   (nullable durante la transición) y `sucursal_id` pasa a nullable.
     * - `saldos_inventario`: nuevo índice único por almacén.
     * - `entregas_uniformes` y `devoluciones`: `almacen_id` de procedencia
     *   (nullable) para la trazabilidad de origen del stock.
     */
    public function up(): void
    {
        Schema::table('saldos_inventario', function (Blueprint $table): void {
            $table->foreignId('almacen_id')->nullable()->after('empresa_id')
                ->constrained('almacenes')->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::table('saldos_inventario', function (Blueprint $table): void {
            $table->dropForeign(['sucursal_id']);
        });
        Schema::table('saldos_inventario', function (Blueprint $table): void {
            $table->unsignedBigInteger('sucursal_id')->nullable()->change();
        });
        Schema::table('saldos_inventario', function (Blueprint $table): void {
            $table->foreign('sucursal_id')->references('id')->on('sucursales')
                ->cascadeOnUpdate()->restrictOnDelete();
            $table->unique(['empresa_id', 'almacen_id', 'activo_id', 'talla_id'], 'saldos_inv_almacen_unico');
            $table->index(['empresa_id', 'almacen_id']);
        });

        Schema::table('movimientos_inventario', function (Blueprint $table): void {
            $table->foreignId('almacen_id')->nullable()->after('empresa_id')
                ->constrained('almacenes')->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::table('movimientos_inventario', function (Blueprint $table): void {
            $table->dropForeign(['sucursal_id']);
        });
        Schema::table('movimientos_inventario', function (Blueprint $table): void {
            $table->unsignedBigInteger('sucursal_id')->nullable()->change();
        });
        Schema::table('movimientos_inventario', function (Blueprint $table): void {
            $table->foreign('sucursal_id')->references('id')->on('sucursales')
                ->cascadeOnUpdate()->restrictOnDelete();
            $table->index(['empresa_id', 'almacen_id', 'activo_id', 'talla_id'], 'movimientos_inv_almacen_idx');
        });

        Schema::table('entregas_uniformes', function (Blueprint $table): void {
            $table->foreignId('almacen_id')->nullable()->after('sucursal_id')
                ->constrained('almacenes')->cascadeOnUpdate()->nullOnDelete();
        });

        Schema::table('devoluciones', function (Blueprint $table): void {
            $table->foreignId('almacen_id')->nullable()->after('sucursal_id')
                ->constrained('almacenes')->cascadeOnUpdate()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('devoluciones', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('almacen_id');
        });

        Schema::table('entregas_uniformes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('almacen_id');
        });

        Schema::table('movimientos_inventario', function (Blueprint $table): void {
            $table->dropIndex('movimientos_inv_almacen_idx');
            $table->dropConstrainedForeignId('almacen_id');
        });

        Schema::table('saldos_inventario', function (Blueprint $table): void {
            $table->dropUnique('saldos_inv_almacen_unico');
            $table->dropIndex(['empresa_id', 'almacen_id']);
            $table->dropConstrainedForeignId('almacen_id');
        });
    }
};
