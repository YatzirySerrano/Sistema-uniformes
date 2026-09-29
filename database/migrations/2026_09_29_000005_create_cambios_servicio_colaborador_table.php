<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Proceso de cambio de servicio de un colaborador CON custodia: guarda la
     * revisión (qué se decidió para cada bien) mientras las devoluciones y
     * redistribuciones necesarias se firman — quizá después y por otros
     * usuarios —, y el cambio sólo se aplica cuando todo está resuelto.
     *
     * No es una segunda fuente de custodia ni de inventario: "resuelto" se
     * DERIVA siempre de las entregas/devoluciones reales. Los `corte_*` son el
     * último id existente al iniciar la revisión, para distinguir las
     * operaciones hechas DURANTE el proceso de las anteriores.
     */
    public function up(): void
    {
        Schema::create('cambios_servicio_colaborador', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('servicio_origen_id')->nullable()->constrained('servicios')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('servicio_destino_id')->nullable()->constrained('servicios')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('estado', 20)->default('pendiente');
            $table->text('motivo')->nullable();
            $table->unsignedBigInteger('corte_detalle_entrega_id')->default(0);
            $table->unsignedBigInteger('corte_detalle_devolucion_id')->default(0);
            $table->unsignedBigInteger('corte_incidencia_id')->default(0);
            $table->foreignId('iniciado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completado_en')->nullable();
            $table->foreignId('cancelado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelado_en')->nullable();
            $table->timestamps();

            $table->index(['colaborador_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cambios_servicio_colaborador');
    }
};
