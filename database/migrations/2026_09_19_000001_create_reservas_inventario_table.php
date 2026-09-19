<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reserva TEMPORAL (apartado, TTL) de inventario mientras un usuario
     * prepara una Entrega o una Devolución — nunca la operación en sí.
     * `tipo` separa conceptualmente los dos dominios aunque compartan tabla:
     * ENTREGA reserva stock disponible de almacén; DEVOLUCIÓN reserva el
     * DERECHO a devolver una custodia pendiente (nunca "reserva stock").
     *
     * Una reserva se considera activa sólo mientras
     * `expira_en > now() AND consumida_en IS NULL AND liberada_en IS NULL`.
     * Una fila expirada NUNCA bloquea nada aunque todavía exista físicamente
     * hasta que la limpie el comando de mantenimiento — la corrección nunca
     * depende de que la limpieza corra exactamente a tiempo.
     */
    public function up(): void
    {
        Schema::create('reservas_inventario', function (Blueprint $table): void {
            $table->id();
            $table->uuid('token')->unique();
            $table->string('tipo', 20);
            $table->foreignId('user_id')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('almacen_id')->nullable()->constrained('almacenes')->nullOnDelete();
            $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->foreignId('entrega_uniforme_id')->nullable()->constrained('entregas_uniformes')->nullOnDelete();
            $table->timestamp('expira_en');
            $table->timestamp('consumida_en')->nullable();
            $table->timestamp('liberada_en')->nullable();
            $table->timestamps();

            $table->index('user_id', 'reservas_inventario_usuario_idx');
            $table->index('expira_en', 'reservas_inventario_expira_idx');
            $table->index(['tipo', 'empresa_id', 'almacen_id'], 'reservas_inventario_tipo_empresa_almacen_idx');
            $table->index('entrega_uniforme_id', 'reservas_inventario_entrega_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservas_inventario');
    }
};
