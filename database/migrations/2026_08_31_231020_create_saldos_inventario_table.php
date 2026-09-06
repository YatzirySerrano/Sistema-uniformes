<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla de ESTADO ACTUAL del inventario, llaveada por
     * EMPRESA + ALMACÉN + ACTIVO + VARIANTE. El almacén es el origen físico
     * del stock; la sucursal es sólo destino/contexto del colaborador y no
     * participa en esta llave. "Sin variante" es `talla_id = NULL` (no existe
     * talla comodín); `talla_ref` es una columna generada
     * (`COALESCE(talla_id, 0)`) que permite un índice único real incluyendo
     * NULL, ya que MySQL/MariaDB no garantizan unicidad entre NULLs en un
     * índice único normal.
     */
    public function up(): void
    {
        Schema::create('saldos_inventario', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('almacen_id')->constrained('almacenes')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('activo_id')->constrained('activos')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('talla_id')->nullable()->constrained('tallas')->nullOnDelete();
            $table->unsignedBigInteger('talla_ref')->virtualAs('coalesce(talla_id, 0)');
            $table->integer('cantidad')->default(0);
            $table->unsignedInteger('minimo')->default(0);
            $table->timestamps();

            $table->unique(['empresa_id', 'almacen_id', 'activo_id', 'talla_ref'], 'saldos_inv_almacen_unico');
            $table->index(['empresa_id', 'almacen_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saldos_inventario');
    }
};
