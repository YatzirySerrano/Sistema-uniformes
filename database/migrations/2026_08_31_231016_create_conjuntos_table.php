<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CONJUNTOS (agrupaciones lógicas de Activos, p. ej. "Traje Hombre" o "Kit
     * Ejecutivo"). Un Conjunto pertenece a UNA empresa y sólo puede incluir
     * Activos de esa misma empresa (validado en backend, no en FK). Es una
     * PLANTILLA sin stock propio: la disponibilidad se calcula en vivo a
     * partir del stock real de cada componente.
     *
     * `conjunto_componentes.talla_id` fija la variante del componente; si es
     * NULL y `talla_libre = true`, la variante se elige durante la entrega.
     */
    public function up(): void
    {
        Schema::create('conjuntos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('nombre', 255);
            $table->string('codigo', 60)->nullable();
            $table->text('descripcion')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['empresa_id', 'codigo']);
            $table->index(['empresa_id', 'activo']);
        });

        Schema::create('conjunto_componentes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conjunto_id')->constrained('conjuntos')->cascadeOnDelete();
            $table->foreignId('activo_id')->constrained('activos')->restrictOnDelete();
            $table->unsignedInteger('cantidad_requerida')->default(1);
            $table->foreignId('talla_id')->nullable()->constrained('tallas')->nullOnDelete();
            $table->boolean('talla_libre')->default(false);
            $table->timestamps();

            $table->index(['conjunto_id']);
            $table->index(['activo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conjunto_componentes');
        Schema::dropIfExists('conjuntos');
    }
};
