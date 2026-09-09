<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Renglón de una ronda de inventario físico. Cada fila representa la
     * relación (ronda ↔ unidad) y guarda AMBAS cosas en una sola tabla:
     *
     * - `esperada = true`  → la unidad estaba en el snapshot al iniciar la ronda.
     * - `esperada = false` → la unidad NO estaba en el snapshot pero se escaneó
     *   igual (creada después de iniciar, o fuera del alcance de almacén).
     * - `escaneado_en` nulo → todavía no se ha escaneado en esta ronda.
     *
     * La clasificación del resumen se DERIVA de esas dos columnas, nunca se
     * persiste ("resultado = faltante" sería redundante):
     *   esperada && escaneado_en  → Encontrado
     *   esperada && !escaneado_en → Faltante / no localizado
     *   !esperada (siempre escaneado) → Encontrado no esperado
     *
     * El `UNIQUE (inventario_fisico_id, unidad_activo_id)` es la protección
     * real contra el doble escaneo: dos lecturas de cámara (o dos dispositivos
     * a la vez) nunca crean dos filas para la misma unidad en la misma ronda.
     */
    public function up(): void
    {
        Schema::create('inventario_fisico_unidades', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventario_fisico_id')->constrained('inventarios_fisicos')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('unidad_activo_id')->constrained('unidades_activo')->cascadeOnUpdate()->restrictOnDelete();

            $table->boolean('esperada')->default(true);
            $table->timestamp('escaneado_en')->nullable();
            $table->foreignId('escaneado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['inventario_fisico_id', 'unidad_activo_id'], 'inv_fisico_unidad_unico');
            $table->index(['inventario_fisico_id', 'esperada', 'escaneado_en'], 'inv_fisico_conteo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventario_fisico_unidades');
    }
};
