<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catálogo de activos de una empresa (evolución de "Prenda"): además de
     * uniformes soporta equipos, dispositivos, accesorios, etc. `tipo_activo_id`
     * y `categoria_id` apuntan a catálogos GLOBALES de plataforma (opcionales
     * e independientes). `categoria` (texto) es espejo temporal de
     * `categoria_id`, sincronizado por `ActivoController`. `tipo_control`
     * distingue seguimiento por cantidad (`saldos_inventario`) de seguimiento
     * individual (`unidades_activo`).
     */
    public function up(): void
    {
        Schema::create('activos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('tipo_activo_id')->nullable()->constrained('tipos_activo')->cascadeOnUpdate()->nullOnDelete();
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->string('categoria')->nullable();
            $table->foreignId('categoria_id')->nullable()->constrained('categorias_activo')->cascadeOnUpdate()->nullOnDelete();
            $table->string('tipo_control', 20)->default('cantidad');
            $table->string('codigo', 60)->nullable();
            $table->string('imagen_ruta')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['empresa_id', 'codigo']);
            $table->index(['empresa_id', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activos');
    }
};
