<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detalles_devolucion', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('devolucion_id')->constrained('devoluciones')->cascadeOnDelete();
            $table->foreignId('prenda_id')->constrained('prendas')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('talla_id')->constrained('tallas')->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedInteger('cantidad');
            $table->string('condicion', 20)->default('reutilizable');
            $table->boolean('reingresa_inventario')->default(true);
            $table->timestamps();

            $table->index('devolucion_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detalles_devolucion');
    }
};
