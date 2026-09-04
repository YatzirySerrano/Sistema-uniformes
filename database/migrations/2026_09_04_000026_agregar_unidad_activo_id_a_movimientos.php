<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Trazabilidad de unidades de seguimiento individual sobre
     * `movimientos_inventario`: cada operación de una unidad (alta, baja,
     * futura entrega/devolución) genera un movimiento con `unidad_activo_id`
     * y `cantidad = 1`. Estos movimientos NO tocan `saldos_inventario` (para
     * seguimiento individual no hay saldo agregado). `talla_id` va nulo.
     *
     * Forward-only.
     */
    public function up(): void
    {
        Schema::table('movimientos_inventario', function (Blueprint $table): void {
            $table->foreignId('unidad_activo_id')->nullable()->after('talla_id')
                ->constrained('unidades_activo')->nullOnDelete();
            $table->index('unidad_activo_id');
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_inventario', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('unidad_activo_id');
        });
    }
};
