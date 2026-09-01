<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_inventario', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('prenda_id')->constrained('prendas')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('talla_id')->constrained('tallas')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('tipo', 30);
            $table->string('direccion', 10);
            $table->unsignedInteger('cantidad');
            $table->integer('existencia_anterior');
            $table->integer('existencia_resultante');
            $table->string('referencia_tipo')->nullable();
            $table->unsignedBigInteger('referencia_id')->nullable();
            $table->string('motivo')->nullable();
            $table->text('notas')->nullable();
            $table->foreignId('realizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ocurrido_en');
            $table->timestamps();

            $table->index(['empresa_id', 'sucursal_id', 'prenda_id', 'talla_id'], 'movimientos_inv_saldo_idx');
            $table->index(['referencia_tipo', 'referencia_id']);
            $table->index('tipo');
            $table->index('ocurrido_en');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_inventario');
    }
};
