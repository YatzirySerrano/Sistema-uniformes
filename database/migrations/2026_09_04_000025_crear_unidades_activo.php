<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * IDENTIFICACIÓN INDIVIDUAL (redefinición funcional). `unidades_activo` es
     * la fuente de verdad física de un activo con `tipo_control = individual`
     * (una laptop, una silla, una herramienta costosa…). A diferencia de la
     * Etapa 2 descartada, NO se captura número de serie / IMEI / etiqueta /
     * MAC: `codigo` lo genera el sistema (`ServicioGeneradorCodigos`, único a
     * nivel plataforma, prefijado por el código de la EMPRESA — nunca por el
     * almacén, para que sobreviva a una transferencia) y `public_token` (UUID)
     * es el identificador estable y no enumerable para el QR
     * (`/activos/unidades/{public_token}`).
     *
     * `estado` (ciclo de posesión: en_almacen | asignada | baja) y `condicion`
     * (salud física: funcionando | en_reparacion | inservible | perdido |
     * robado) son ejes independientes — ver `App\Models\UnidadActivo`.
     *
     * `colaborador_id` es sólo la referencia de ESTADO ACTUAL (UX); el
     * histórico real vive en `entregas`/`devoluciones`/`movimientos_inventario`.
     *
     * No hay saldo agregado para estas unidades: cada alta/baja genera un
     * `MovimientoInventario` con `unidad_activo_id` (ver migración siguiente)
     * que NO toca `saldos_inventario`.
     *
     * Forward-only. En una BD nueva la tabla nace vacía.
     */
    public function up(): void
    {
        Schema::create('unidades_activo', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('activo_id')->constrained('activos')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('almacen_id')->constrained('almacenes')->cascadeOnUpdate()->restrictOnDelete();

            $table->string('codigo', 40)->unique();
            $table->uuid('public_token')->unique();

            $table->string('estado', 20)->default('en_almacen');
            $table->string('condicion', 20)->default('funcionando');
            $table->text('observaciones')->nullable();

            // Referencia SECUNDARIA (UX): el tenedor actual cuando está
            // Asignada, o el último responsable si se marcó Perdida/Robada.
            // La historia real vive en entregas/devoluciones/movimientos.
            $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('dado_de_baja_en')->nullable();
            $table->string('motivo_baja', 255)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['empresa_id', 'almacen_id', 'activo_id', 'estado'], 'unidades_scope_idx');
            $table->index(['activo_id', 'estado']);
            $table->index('estado');
            $table->index('condicion');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unidades_activo');
    }
};
