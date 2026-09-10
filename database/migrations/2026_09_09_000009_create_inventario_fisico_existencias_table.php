<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot congelado de las existencias POR CANTIDAD (activos no
     * serializados) de una ronda de inventario físico. Complementa
     * `inventario_fisico_unidades` (unidades identificadas con QR): las prendas
     * no tienen QR individual, así que su comprobación es manual — se cuenta y
     * se compara contra lo que el sistema esperaba en ese almacén al iniciar.
     *
     * - `cantidad_esperada` se toma de `saldos_inventario` al crear la ronda y
     *   NO se recalcula (aunque el stock cambie después).
     * - `cantidad_contada` nulo → renglón pendiente de verificar.
     * - El resultado (coincide / faltante / sobrante) se DERIVA de
     *   `cantidad_contada` vs `cantidad_esperada`, nunca se persiste.
     *
     * `empresa_id` / `almacen_id` NO se copian: se derivan inequívocamente de
     * la ronda (`inventarios_fisicos`). El módulo sólo COMPARA: jamás ajusta
     * `saldos_inventario`.
     */
    public function up(): void
    {
        Schema::create('inventario_fisico_existencias', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventario_fisico_id')->constrained('inventarios_fisicos')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('activo_id')->constrained('activos')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('talla_id')->nullable()->constrained('tallas')->cascadeOnUpdate()->restrictOnDelete();
            // Columna generada `COALESCE(talla_id, 0)` para un índice único real
            // incluyendo el caso "sin variante" (mismo patrón que saldos_inventario).
            $table->unsignedBigInteger('talla_ref')->virtualAs('coalesce(talla_id, 0)');

            $table->unsignedInteger('cantidad_esperada');
            $table->unsignedInteger('cantidad_contada')->nullable();
            $table->foreignId('verificada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verificada_en')->nullable();

            $table->timestamps();

            $table->unique(['inventario_fisico_id', 'activo_id', 'talla_ref'], 'inv_fisico_existencia_unico');
            $table->index('inventario_fisico_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventario_fisico_existencias');
    }
};
