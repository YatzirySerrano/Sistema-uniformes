<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prenda_talla', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prenda_id')->constrained('prendas')->cascadeOnDelete();
            $table->foreignId('talla_id')->constrained('tallas')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['prenda_id', 'talla_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prenda_talla');
    }
};
