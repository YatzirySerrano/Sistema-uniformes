<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servicios', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contrato_id')->constrained('contratos')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('codigo', 20)->unique();
            $table->string('nombre');
            $table->string('direccion')->nullable();
            $table->text('descripcion')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['contrato_id', 'nombre']);
            $table->index(['contrato_id', 'activo']);
            $table->index(['sucursal_id', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servicios');
    }
};
