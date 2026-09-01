<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devoluciones', function (Blueprint $table): void {
            $table->id();
            $table->string('folio')->unique();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('entrega_uniforme_id')->nullable()->constrained('entregas_uniformes')->nullOnDelete();
            $table->foreignId('registrada_por')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->date('fecha');
            $table->string('motivo')->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['empresa_id', 'sucursal_id']);
            $table->index('colaborador_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devoluciones');
    }
};
