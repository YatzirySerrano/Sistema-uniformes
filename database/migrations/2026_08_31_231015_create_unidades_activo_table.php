<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * IDENTIFICACIÓN INDIVIDUAL: `unidades_activo` es la fuente de verdad
     * física de un activo con `tipo_control = individual` (una laptop, una
     * silla, una herramienta costosa…). No se captura número de serie / IMEI /
     * etiqueta / MAC: `codigo` lo genera el sistema (único a nivel plataforma,
     * prefijado por el código de la EMPRESA) y `public_token` (UUID) es el
     * identificador permanente y no enumerable usado en el QR.
     *
     * `estado` (ciclo de posesión) y `condicion` (salud física) son ejes
     * independientes. `colaborador_id` es sólo la referencia de ESTADO ACTUAL;
     * el histórico real vive en movimientos/entregas/devoluciones. No hay
     * saldo agregado para estas unidades.
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

            $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('dado_de_baja_en')->nullable();
            $table->string('motivo_baja', 255)->nullable();
            $table->string('incidencia_motivo', 255)->nullable();
            $table->timestamp('incidencia_registrada_en')->nullable();
            $table->foreignId('incidencia_registrada_por')->nullable()->constrained('users')->nullOnDelete();

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
