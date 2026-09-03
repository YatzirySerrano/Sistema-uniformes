<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catálogo real de categorías de activo por empresa (Camisola, Pantalón,
     * Laptop, Teléfono celular…). Sustituye al texto libre `activos.categoria`.
     *
     * Puede relacionarse opcionalmente con un tipo de activo para organizar el
     * catálogo, pero no lo exige: `tipo_activo_id` es nullable y no restringe la
     * selección.
     */
    public function up(): void
    {
        Schema::create('categorias_activo', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('tipo_activo_id')->nullable()->constrained('tipos_activo')->cascadeOnUpdate()->nullOnDelete();
            $table->string('nombre');
            $table->string('codigo', 60)->nullable();
            $table->boolean('activa')->default(true);
            $table->timestamps();

            $table->unique(['empresa_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categorias_activo');
    }
};
