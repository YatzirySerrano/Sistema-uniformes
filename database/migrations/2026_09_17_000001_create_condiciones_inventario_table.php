<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Condición física de existencias POR CANTIDAD (Dañado / Baja), marcada
     * directamente desde el stock disponible — no desde una Devolución.
     * Historia append-only, igual filosofía que `movimientos_inventario`:
     * cada fila es un evento (marcar o restaurar), nunca se edita ni se
     * borra. El estado ACTUAL ("cuántas dañadas hay ahora") se calcula
     * sumando esta tabla (ver `ServicioEstadoInventario`), nunca se persiste
     * un contador aparte — mismo criterio que ya usa ese servicio para el
     * dañado/baja que viene de devoluciones.
     *
     * `condicion` reutiliza los valores de `App\Enums\CondicionDevolucion`
     * (danado|baja) — mismo vocabulario que ya usa el dominio para "Dañado"
     * y "Baja" en devoluciones, nunca `reutilizable` aquí. `tipo` reutiliza
     * `App\Enums\TipoMovimiento` (incidencia = entra a dañado, baja = entra
     * a baja, recuperacion = sale de dañado de vuelta a disponible) — el
     * mismo vocabulario que ya usa `UnidadActivo`, aplicado ahora también a
     * inventario por cantidad. Cada fila referencia el `MovimientoInventario`
     * real que ajustó `saldos_inventario` (vía `ServicioInventario::registrarMovimiento()`,
     * sin arquitectura paralela): "Disponible" nunca se calcula aparte, sigue
     * siendo el saldo vivo.
     */
    public function up(): void
    {
        Schema::create('condiciones_inventario', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('almacen_id')->constrained('almacenes')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('activo_id')->constrained('activos')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('talla_id')->nullable()->constrained('tallas')->nullOnDelete();
            $table->foreignId('movimiento_inventario_id')->constrained('movimientos_inventario')->restrictOnDelete();
            $table->string('condicion', 20);
            $table->string('tipo', 30);
            $table->unsignedInteger('cantidad');
            $table->string('motivo');
            $table->foreignId('realizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['empresa_id', 'almacen_id', 'activo_id', 'talla_id'], 'condiciones_inv_almacen_idx');
            $table->index('condicion');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('condiciones_inventario');
    }
};
