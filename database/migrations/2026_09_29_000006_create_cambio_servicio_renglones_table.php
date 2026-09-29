<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un renglón por bien revisado: un renglón de entrega por cantidad
     * (`detalle_entrega_id`, la custodia se lleva por renglón de origen) o una
     * unidad identificada (`unidad_activo_id`). `cantidad_revisada` es lo que
     * había bajo custodia al revisarlo; la decisión la reparte entre
     * mantener / devolver / redistribuir (a `destinatario_id`). Los snapshots
     * de nombre hacen legible el historial aunque el catálogo cambie.
     */
    public function up(): void
    {
        Schema::create('cambio_servicio_renglones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cambio_servicio_colaborador_id')->constrained('cambios_servicio_colaborador')->cascadeOnDelete();
            $table->foreignId('detalle_entrega_id')->nullable()->constrained('detalles_entrega')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('unidad_activo_id')->nullable()->constrained('unidades_activo')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('activo_nombre_snapshot');
            $table->string('talla_valor_snapshot')->nullable();
            $table->string('unidad_codigo_snapshot')->nullable();
            $table->unsignedInteger('cantidad_revisada');
            $table->unsignedInteger('cantidad_mantener')->default(0);
            $table->unsignedInteger('cantidad_devolver')->default(0);
            $table->unsignedInteger('cantidad_redistribuir')->default(0);
            $table->foreignId('destinatario_id')->nullable()->constrained('colaboradores')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cambio_servicio_renglones');
    }
};
