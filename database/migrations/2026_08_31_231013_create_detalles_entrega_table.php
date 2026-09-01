<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detalles_entrega', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entrega_uniforme_id')->constrained('entregas_uniformes')->cascadeOnDelete();
            $table->foreignId('prenda_id')->constrained('prendas')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('talla_id')->constrained('tallas')->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedInteger('cantidad');
            $table->string('prenda_nombre_snapshot');
            $table->string('talla_valor_snapshot', 30);
            $table->timestamps();

            $table->index('entrega_uniforme_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detalles_entrega');
    }
};
