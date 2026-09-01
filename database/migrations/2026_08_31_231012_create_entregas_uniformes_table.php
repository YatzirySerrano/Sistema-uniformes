<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entregas_uniformes', function (Blueprint $table): void {
            $table->id();
            $table->string('folio')->unique();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('encargado_id')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('estado', 30)->default('pendiente_firma');
            $table->date('fecha_entrega');
            $table->text('notas')->nullable();
            $table->timestamp('confirmada_en')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['empresa_id', 'sucursal_id', 'estado']);
            $table->index('colaborador_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entregas_uniformes');
    }
};
