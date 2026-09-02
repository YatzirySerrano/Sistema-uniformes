<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catálogo de tipos/categorías de activo por empresa (Uniforme / Prenda,
     * Equipo de cómputo, Dispositivo móvil, Accesorio, Otro…). Es extensible: el
     * administrador podrá gestionarlo desde su propia pantalla en un bloque
     * posterior.
     */
    public function up(): void
    {
        Schema::create('tipos_activo', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('nombre');
            $table->string('codigo', 60)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['empresa_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipos_activo');
    }
};
