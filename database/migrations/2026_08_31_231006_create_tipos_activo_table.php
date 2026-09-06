<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catálogo de tipos de activo **de plataforma** (Prenda, Equipo de
     * cómputo, Dispositivo móvil, Accesorio, Otro…). Global: visible para
     * todas las empresas por igual, sin habilitación por empresa. Opcional
     * en el activo.
     */
    public function up(): void
    {
        Schema::create('tipos_activo', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre');
            $table->string('nombre_normalizado')->default('');
            $table->string('codigo', 60)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique('nombre_normalizado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipos_activo');
    }
};
