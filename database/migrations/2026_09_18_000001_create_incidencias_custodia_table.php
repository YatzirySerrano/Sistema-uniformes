<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Robo / pérdida de activos POR CANTIDAD que ya están bajo custodia de un
     * colaborador (entregados y todavía no devueltos) — nunca almacén: las
     * piezas ya habían salido del inventario desde la entrega, así que
     * reportar la incidencia NO genera ningún `MovimientoInventario` ni toca
     * `saldos_inventario` (eso ya lo hizo la entrega original). Es el
     * equivalente, para control por cantidad, de `MarcarUnidadIncidencia`
     * para `UnidadActivo` — misma idea de "responsabilidad abierta que no
     * vuelve sola a almacén", pero persistida aquí porque un renglón de
     * cantidad no tiene una fila individual que pueda cambiar de estado.
     *
     * Historia append-only (sin `deleted_at`): cada fila es un evento. El
     * pendiente real de un renglón de entrega (`ServicioCustodiaColaborador::pendientesPorDetalle()`)
     * se calcula restando TANTO lo devuelto confirmado COMO lo aquí
     * reportado — nunca se descuenta dos veces la misma pieza.
     */
    public function up(): void
    {
        Schema::create('incidencias_custodia', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('entrega_uniforme_id')->constrained('entregas_uniformes')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('detalle_entrega_id')->constrained('detalles_entrega')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('activo_id')->constrained('activos')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('talla_id')->nullable()->constrained('tallas')->nullOnDelete();
            $table->string('activo_nombre_snapshot');
            $table->string('talla_valor_snapshot')->nullable();
            $table->string('tipo', 20);
            $table->unsignedInteger('cantidad');
            $table->string('motivo');
            $table->text('observacion')->nullable();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('detalle_entrega_id', 'incidencias_custodia_detalle_idx');
            $table->index('colaborador_id', 'incidencias_custodia_colaborador_idx');
            $table->index('empresa_id', 'incidencias_custodia_empresa_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidencias_custodia');
    }
};
