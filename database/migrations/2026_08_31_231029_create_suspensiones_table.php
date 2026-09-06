<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cascada de desactivación NO destructiva. Cuando una Empresa, Sucursal o
     * Activo se desactiva, sus dependientes activos en ese momento (nunca los
     * que ya estaban inactivos por otra causa) también quedan inactivos, y
     * aquí queda el rastro: quién los desactivó (`causante_*`), qué columna
     * de "activo" tocar al reactivar (`columna_activo` — Empresa/Sucursal/
     * Área usan `activa`, el resto `activo`), y si ya fueron reactivados
     * (`levantada_en`). Reactivar el causante NO reactiva automáticamente sus
     * dependientes: la reactivación es selectiva. Nunca toca históricos
     * (movimientos, entregas…), sólo el flag de estado de catálogo/config.
     */
    public function up(): void
    {
        Schema::create('suspensiones', function (Blueprint $table): void {
            $table->id();
            $table->string('entidad_type');
            $table->unsignedBigInteger('entidad_id');
            $table->string('columna_activo', 20);
            $table->string('causante_type');
            $table->unsignedBigInteger('causante_id');
            $table->string('motivo', 255)->nullable();
            $table->foreignId('suspendida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('suspendida_en')->useCurrent();
            $table->timestamp('levantada_en')->nullable();
            $table->foreignId('levantada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['entidad_type', 'entidad_id']);
            $table->index(['causante_type', 'causante_id', 'levantada_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suspensiones');
    }
};
