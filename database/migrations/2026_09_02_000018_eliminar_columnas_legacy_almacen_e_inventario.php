<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Elimina la deuda estructural de la arquitectura anterior una vez que el
     * código usa `almacen_empresa` y el inventario por almacén como únicas
     * fuentes de verdad:
     *
     * - `almacenes.empresa_id`  → sustituida por la pivote `almacen_empresa`.
     * - `almacen_sucursal`      → el almacén ya no se relaciona con sucursales;
     *                             el almacén de origen de una operación se
     *                             elige explícitamente / se deriva de la empresa.
     * - `saldos_inventario.sucursal_id` → la tabla de estado actual sólo se
     *                             llavea por `empresa + almacen + activo + talla`.
     *                             `movimientos_inventario.sucursal_id` SÍ se
     *                             conserva como procedencia histórica.
     *
     * Forward-only. En una BD nueva de producción corre sin filas legacy que
     * consolidar (las migraciones previas dejan todo en su sitio).
     */
    public function up(): void
    {
        Schema::table('saldos_inventario', function (Blueprint $table): void {
            $table->dropForeign(['sucursal_id']);
        });

        Schema::table('saldos_inventario', function (Blueprint $table): void {
            $table->dropUnique('saldos_inventario_unico');
            $table->dropIndex('saldos_inventario_empresa_id_sucursal_id_index');
        });

        Schema::table('saldos_inventario', function (Blueprint $table): void {
            $table->dropColumn('sucursal_id');
        });

        Schema::table('saldos_inventario', function (Blueprint $table): void {
            $table->unsignedBigInteger('almacen_id')->nullable(false)->change();
        });

        Schema::dropIfExists('almacen_sucursal');

        Schema::table('almacenes', function (Blueprint $table): void {
            $table->dropForeign(['empresa_id']);
        });

        Schema::table('almacenes', function (Blueprint $table): void {
            $table->dropUnique('almacenes_empresa_id_codigo_unique');
            $table->dropIndex('almacenes_empresa_id_activo_index');
        });

        Schema::table('almacenes', function (Blueprint $table): void {
            $table->dropColumn('empresa_id');
        });

        Schema::table('almacenes', function (Blueprint $table): void {
            $table->unique('codigo');
            $table->index('activo');
        });
    }

    public function down(): void
    {
        Schema::table('almacenes', function (Blueprint $table): void {
            $table->dropUnique(['codigo']);
            $table->dropIndex(['activo']);
        });

        Schema::table('almacenes', function (Blueprint $table): void {
            $table->foreignId('empresa_id')->nullable()->after('id')
                ->constrained('empresas')->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::table('almacenes', function (Blueprint $table): void {
            $table->unique(['empresa_id', 'codigo'], 'almacenes_empresa_id_codigo_unique');
            $table->index(['empresa_id', 'activo'], 'almacenes_empresa_id_activo_index');
        });

        Schema::create('almacen_sucursal', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('almacen_id')->constrained('almacenes')->cascadeOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['almacen_id', 'sucursal_id']);
        });

        Schema::table('saldos_inventario', function (Blueprint $table): void {
            $table->unsignedBigInteger('almacen_id')->nullable()->change();
        });

        Schema::table('saldos_inventario', function (Blueprint $table): void {
            $table->foreignId('sucursal_id')->nullable()->after('almacen_id')
                ->constrained('sucursales')->cascadeOnUpdate()->restrictOnDelete();
            $table->unique(['empresa_id', 'sucursal_id', 'activo_id', 'talla_id'], 'saldos_inventario_unico');
            $table->index(['empresa_id', 'sucursal_id'], 'saldos_inventario_empresa_id_sucursal_id_index');
        });
    }
};
