<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contratos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('codigo', 20);
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['empresa_id', 'codigo']);
            $table->unique(['empresa_id', 'nombre']);
            $table->index(['empresa_id', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contratos');
    }
};
