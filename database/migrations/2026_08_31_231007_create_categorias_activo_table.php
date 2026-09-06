<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catálogo de categorías de activo **de plataforma** (Camisola, Pantalón,
     * Laptop, Teléfono celular…). Global: visible para todas las empresas por
     * igual, sin habilitación por empresa. Puede relacionarse opcionalmente
     * con un tipo de activo, sin exigirlo.
     */
    public function up(): void
    {
        Schema::create('categorias_activo', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tipo_activo_id')->nullable()->constrained('tipos_activo')->cascadeOnUpdate()->nullOnDelete();
            $table->string('nombre');
            $table->string('nombre_normalizado')->default('');
            $table->string('codigo', 60)->nullable();
            $table->boolean('activa')->default(true);
            $table->timestamps();

            $table->unique('nombre_normalizado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categorias_activo');
    }
};
