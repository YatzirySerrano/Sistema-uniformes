<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Evolución "Prendas → Activos" mediante renombrado in-place (cero pérdida de
     * datos: no se copian filas, sólo cambian nombres de tabla y columnas y se
     * añaden campos de clasificación).
     *
     * - `prendas`            → `activos`      (`activa`→`activo`, `codigo_interno`→`codigo`)
     * - `prenda_talla`       → `activo_talla` (`prenda_id`→`activo_id`)
     * - `saldos_inventario`, `movimientos_inventario`, `detalles_entrega`,
     *   `detalles_devolucion`: `prenda_id`→`activo_id`
     * - `detalles_entrega.prenda_nombre_snapshot` → `activo_nombre_snapshot`
     *
     * SQLite (dev) ≥ 3.25 y MySQL/MariaDB actualizan las referencias de clave
     * foránea al renombrar la tabla/columna.
     */
    public function up(): void
    {
        Schema::rename('prendas', 'activos');
        Schema::rename('prenda_talla', 'activo_talla');

        Schema::table('activos', function (Blueprint $table): void {
            $table->renameColumn('activa', 'activo');
            $table->renameColumn('codigo_interno', 'codigo');
        });

        Schema::table('activos', function (Blueprint $table): void {
            $table->foreignId('tipo_activo_id')->nullable()->after('empresa_id')
                ->constrained('tipos_activo')->cascadeOnUpdate()->nullOnDelete();
            $table->string('tipo_control', 20)->default('cantidad')->after('categoria');
        });

        Schema::table('activo_talla', function (Blueprint $table): void {
            $table->renameColumn('prenda_id', 'activo_id');
        });

        Schema::table('saldos_inventario', function (Blueprint $table): void {
            $table->renameColumn('prenda_id', 'activo_id');
        });

        Schema::table('movimientos_inventario', function (Blueprint $table): void {
            $table->renameColumn('prenda_id', 'activo_id');
        });

        Schema::table('detalles_entrega', function (Blueprint $table): void {
            $table->renameColumn('prenda_id', 'activo_id');
            $table->renameColumn('prenda_nombre_snapshot', 'activo_nombre_snapshot');
        });

        Schema::table('detalles_devolucion', function (Blueprint $table): void {
            $table->renameColumn('prenda_id', 'activo_id');
        });
    }

    public function down(): void
    {
        Schema::table('detalles_devolucion', function (Blueprint $table): void {
            $table->renameColumn('activo_id', 'prenda_id');
        });

        Schema::table('detalles_entrega', function (Blueprint $table): void {
            $table->renameColumn('activo_id', 'prenda_id');
            $table->renameColumn('activo_nombre_snapshot', 'prenda_nombre_snapshot');
        });

        Schema::table('movimientos_inventario', function (Blueprint $table): void {
            $table->renameColumn('activo_id', 'prenda_id');
        });

        Schema::table('saldos_inventario', function (Blueprint $table): void {
            $table->renameColumn('activo_id', 'prenda_id');
        });

        Schema::table('activo_talla', function (Blueprint $table): void {
            $table->renameColumn('activo_id', 'prenda_id');
        });

        Schema::table('activos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tipo_activo_id');
            $table->dropColumn('tipo_control');
        });

        Schema::table('activos', function (Blueprint $table): void {
            $table->renameColumn('activo', 'activa');
            $table->renameColumn('codigo', 'codigo_interno');
        });

        Schema::rename('activo_talla', 'prenda_talla');
        Schema::rename('activos', 'prendas');
    }
};
