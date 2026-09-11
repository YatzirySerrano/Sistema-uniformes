<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Perfil técnico (Celular / Computadora / Tablet) de una categoría de
     * activo, 1:1 con `categorias_activo`.
     *
     * Se guarda APARTE de `categorias_activo.codigo` a propósito: el `codigo`
     * es el identificador operativo del catálogo y el perfil técnico es qué
     * datos por unidad se piden — son conceptos distintos que no deben
     * destruirse mutuamente. `categorias_activo.codigo` y `tipos_activo.codigo`
     * (p. ej. `TAC-0001`) quedan intactos y libres para su uso operativo.
     *
     * NO EAV, NO JSON: una columna `perfil` con tres valores. Las categorías
     * sin fila aquí no tienen perfil técnico (no se muestra el formulario
     * especializado) hasta que un administrador las clasifique.
     */
    public function up(): void
    {
        Schema::create('categoria_activo_perfil_tecnico', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('categoria_activo_id')
                ->unique()
                ->constrained('categorias_activo')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->string('perfil', 20); // celular | computadora | tablet
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categoria_activo_perfil_tecnico');
    }
};
