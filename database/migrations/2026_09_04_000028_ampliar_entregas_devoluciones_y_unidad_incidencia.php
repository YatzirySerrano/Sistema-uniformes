<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cierre definitivo de Entregas/Devoluciones (redefinición funcional):
     *
     * - `detalles_entrega`: `unidad_activo_id` (renglón de una unidad de
     *   seguimiento individual entregada — `talla_id`/`cantidad` no aplican,
     *   `cantidad` se conserva en 1 por fila) y `conjunto_id` +
     *   `conjunto_nombre_snapshot` (procedencia informativa: el renglón sigue
     *   siendo un componente real e independiente para efectos de stock;
     *   agregar un activo suelto a una entrega con conjunto NO modifica la
     *   definición del conjunto).
     * - `detalles_devolucion`: `unidad_activo_id` (devolución de una unidad
     *   individual), `detalle_entrega_id` (renglón de origen — permite
     *   acumular "cuánto se ha devuelto ya" de ESE renglón concreto y
     *   rechazar devolver más de lo pendiente) y `condicion_unidad`
     *   (`CondicionUnidadActivo` — Funcionando/EnReparacion/Inservible; NUNCA
     *   Perdido/Robado, eso es una incidencia, no una devolución). Columna
     *   separada de `condicion` (`CondicionDevolucion`, para renglones por
     *   cantidad) porque son dos enums con valores distintos: mezclarlos en
     *   una sola columna rompería el cast tipado de Eloquent.
     * - `unidades_activo`: columnas de auditoría de incidencia
     *   (`App\Acciones\MarcarUnidadIncidencia`) — Perdido/Robado nunca pasa
     *   por devolución ni limpia `colaborador_id` (se conserva el último
     *   responsable para trazabilidad).
     *
     * Forward-only. En una BD nueva todas las columnas nacen vacías/nulas.
     */
    public function up(): void
    {
        Schema::table('detalles_entrega', function (Blueprint $table): void {
            $table->foreignId('unidad_activo_id')->nullable()->after('talla_id')
                ->constrained('unidades_activo')->nullOnDelete();
            $table->foreignId('conjunto_id')->nullable()->after('unidad_activo_id')
                ->constrained('conjuntos')->nullOnDelete();
            $table->string('conjunto_nombre_snapshot')->nullable()->after('conjunto_id');
        });

        Schema::table('detalles_devolucion', function (Blueprint $table): void {
            $table->foreignId('unidad_activo_id')->nullable()->after('talla_id')
                ->constrained('unidades_activo')->nullOnDelete();
            $table->foreignId('detalle_entrega_id')->nullable()->after('unidad_activo_id')
                ->constrained('detalles_entrega')->nullOnDelete();
            $table->string('condicion_unidad', 20)->nullable()->after('condicion');
        });

        Schema::table('unidades_activo', function (Blueprint $table): void {
            $table->string('incidencia_motivo', 255)->nullable()->after('motivo_baja');
            $table->timestamp('incidencia_registrada_en')->nullable()->after('incidencia_motivo');
            $table->foreignId('incidencia_registrada_por')->nullable()->after('incidencia_registrada_en')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('unidades_activo', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('incidencia_registrada_por');
            $table->dropColumn(['incidencia_motivo', 'incidencia_registrada_en']);
        });

        Schema::table('detalles_devolucion', function (Blueprint $table): void {
            $table->dropColumn('condicion_unidad');
            $table->dropConstrainedForeignId('detalle_entrega_id');
            $table->dropConstrainedForeignId('unidad_activo_id');
        });

        Schema::table('detalles_entrega', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('conjunto_id');
            $table->dropColumn('conjunto_nombre_snapshot');
            $table->dropConstrainedForeignId('unidad_activo_id');
        });
    }
};
