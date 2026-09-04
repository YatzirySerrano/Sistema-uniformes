<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CATÁLOGOS GLOBALES (redefinición funcional): `tipos_activo`,
     * `categorias_activo` y `tallas` dejan de habilitarse por empresa. Son
     * catálogos de plataforma visibles para TODAS las empresas por igual; el
     * único estado que les queda es el global (`activo`/`activa`).
     *
     * Se eliminan los pivotes `tipo_activo_empresa`, `categoria_activo_empresa`
     * y `talla_empresa`: sólo contenían banderas de habilitación (no historial
     * ni datos operativos). No tocan `activos.tipo_activo_id`,
     * `activos.categoria_id`, `activo_talla`, `saldos_inventario`,
     * `movimientos_inventario`, `detalles_entrega` ni `detalles_devolucion` —
     * los activos existentes conservan su tipo/categoría/variante intactos.
     *
     * Forward-only. `down()` recrea los pivotes vacíos (no hay backfill
     * razonable: la habilitación por empresa deja de existir como concepto).
     */
    public function up(): void
    {
        Schema::dropIfExists('tipo_activo_empresa');
        Schema::dropIfExists('categoria_activo_empresa');
        Schema::dropIfExists('talla_empresa');
    }

    public function down(): void
    {
        Schema::create('tipo_activo_empresa', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tipo_activo_id')->constrained('tipos_activo')->cascadeOnDelete();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['tipo_activo_id', 'empresa_id']);
        });

        Schema::create('categoria_activo_empresa', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('categoria_activo_id')->constrained('categorias_activo')->cascadeOnDelete();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['categoria_activo_id', 'empresa_id']);
        });

        Schema::create('talla_empresa', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('talla_id')->constrained('tallas')->cascadeOnDelete();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['talla_id', 'empresa_id']);
        });
    }
};
