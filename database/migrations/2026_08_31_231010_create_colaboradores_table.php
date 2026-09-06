<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `area_id` es la fuente de verdad de la relación Colaborador → Área.
     * La columna de texto `area` se conserva como espejo temporal para
     * compatibilidad con el importador, el exportador y el snapshot de acuse,
     * hasta la reingeniería del módulo Colaboradores.
     */
    public function up(): void
    {
        Schema::create('colaboradores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('numero_empleado', 60);
            $table->string('nombre_completo');
            $table->string('puesto')->nullable();
            $table->string('area')->nullable();
            $table->foreignId('area_id')->nullable()->constrained('areas')->cascadeOnUpdate()->nullOnDelete();
            $table->string('correo')->nullable();
            $table->string('foto_ruta')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['empresa_id', 'numero_empleado']);
            $table->index(['empresa_id', 'sucursal_id', 'activo']);
            $table->index(['empresa_id', 'area_id']);
            $table->index('nombre_completo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('colaboradores');
    }
};
