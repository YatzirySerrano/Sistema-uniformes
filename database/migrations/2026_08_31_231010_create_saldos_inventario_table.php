<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saldos_inventario', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('prenda_id')->constrained('prendas')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('talla_id')->constrained('tallas')->cascadeOnUpdate()->restrictOnDelete();
            $table->integer('cantidad')->default(0);
            $table->unsignedInteger('minimo')->default(0);
            $table->timestamps();

            $table->unique(['empresa_id', 'sucursal_id', 'prenda_id', 'talla_id'], 'saldos_inventario_unico');
            $table->index(['empresa_id', 'sucursal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saldos_inventario');
    }
};
