<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('correcciones_entrega', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entrega_uniforme_id')->constrained('entregas_uniformes')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('corregida_por')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->text('motivo');
            $table->json('valores_anteriores');
            $table->json('valores_nuevos');
            $table->timestamps();

            $table->index('entrega_uniforme_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('correcciones_entrega');
    }
};
