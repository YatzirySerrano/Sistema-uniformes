<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conecta entidades ya existentes con el nuevo modelo `Servicio` (ubicación
 * operativa de guardias/activos, ver módulo Contratos/Servicios). Las dos
 * alteraciones viajan juntas porque ninguna tiene sentido por separado: son
 * la ubicación VIGENTE del colaborador y el snapshot HISTÓRICO de la entrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('colaboradores', function (Blueprint $table): void {
            // Estado vigente, mutable: sin ruta de borrado real de Servicio,
            // `nullOnDelete` es sólo defensivo a nivel BD.
            $table->foreignId('servicio_actual_id')->nullable()
                ->after('area_id')
                ->constrained('servicios')->cascadeOnUpdate()->nullOnDelete();
            $table->index('servicio_actual_id');
        });

        Schema::table('entregas_uniformes', function (Blueprint $table): void {
            // Snapshot histórico: `restrictOnDelete` garantiza a nivel BD que
            // jamás se pierda silenciosamente (borrar un Servicio nunca debe
            // borrar ni vaciar entregas ya registradas).
            $table->foreignId('servicio_id')->nullable()
                ->after('almacen_id')
                ->constrained('servicios')->cascadeOnUpdate()->restrictOnDelete();
            $table->index('servicio_id');
        });
    }

    public function down(): void
    {
        Schema::table('entregas_uniformes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('servicio_id');
        });

        Schema::table('colaboradores', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('servicio_actual_id');
        });
    }
};
