<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redistribución de custodia (colaborador custodio → colaborador) como
     * operación DISTINTA de la salida de almacén, sin tablas paralelas de
     * "inventario de supervisores":
     *
     *  - `entregas_uniformes.colaborador_origen_id`: NULL = salida de almacén
     *    (flujo de siempre, `almacen_id` = origen del stock); con valor = la
     *    entrega redistribuye bienes que YA estaban bajo la custodia de ese
     *    colaborador (no descuenta stock; `almacen_id` queda NULL).
     *  - `detalles_entrega.detalle_origen_id`: renglón de la custodia del que
     *    salieron las piezas/unidad. Es lo que permite derivar la custodia
     *    actual por cantidad (entregado − devuelto − incidencias −
     *    redistribuido) sin duplicar saldos, y reconstruir la cadena
     *    Almacén → Supervisor → Colaborador renglón por renglón.
     *
     * Ambas columnas son nullable: las entregas existentes quedan intactas
     * como salidas de almacén.
     */
    public function up(): void
    {
        Schema::table('entregas_uniformes', function (Blueprint $table): void {
            $table->foreignId('colaborador_origen_id')->nullable()->after('almacen_id')
                ->constrained('colaboradores')->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::table('detalles_entrega', function (Blueprint $table): void {
            $table->foreignId('detalle_origen_id')->nullable()->after('unidad_activo_id')
                ->constrained('detalles_entrega')->cascadeOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('detalles_entrega', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('detalle_origen_id');
        });

        Schema::table('entregas_uniformes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('colaborador_origen_id');
        });
    }
};
