<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sucursales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('codigo');
            $table->string('nombre');
            $table->string('direccion')->nullable();
            $table->string('telefono', 40)->nullable();
            $table->boolean('activa')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['empresa_id', 'codigo']);
            $table->index(['empresa_id', 'activa']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sucursales');
    }
};
