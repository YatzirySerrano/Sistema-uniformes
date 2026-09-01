<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prendas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->string('categoria')->nullable();
            $table->string('codigo_interno', 60)->nullable();
            $table->string('imagen_ruta')->nullable();
            $table->boolean('activa')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['empresa_id', 'codigo_interno']);
            $table->index(['empresa_id', 'activa']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prendas');
    }
};
