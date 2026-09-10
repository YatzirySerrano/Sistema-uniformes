<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Encabezado de un traspaso de inventario: mueve existencias de
     * `empresa_origen + almacen_origen` a `empresa_destino + almacen_destino`
     * en una sola operación atómica. `tipo` distingue "misma_empresa" (sólo
     * cambia de almacén) de "interempresa" (cambia de razón social).
     *
     * Es historia append-only, como `movimientos_inventario`: no se edita ni
     * se borra por operaciones normales. Las FKs a empresas/almacenes son
     * `restrictOnDelete` para que un borrado de catálogo nunca arrastre la
     * historia de traspasos. Los renglones cuelgan de aquí
     * (`cascadeOnDelete`), pero el encabezado en sí nunca se elimina.
     *
     * NO sustituye a `movimientos_inventario`: cada renglón sigue generando
     * sus dos movimientos reales (salida en contexto origen, entrada en
     * contexto destino), correlacionados con este encabezado por
     * `referencia_tipo`/`referencia_id`.
     */
    public function up(): void
    {
        Schema::create('traspasos_inventario', function (Blueprint $table): void {
            $table->id();
            $table->string('folio')->unique();
            $table->string('tipo', 20);
            $table->foreignId('empresa_origen_id')->constrained('empresas')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('almacen_origen_id')->constrained('almacenes')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('empresa_destino_id')->constrained('empresas')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('almacen_destino_id')->constrained('almacenes')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('estado', 20)->default('completado');
            $table->string('motivo', 255)->nullable();
            $table->text('notas')->nullable();
            $table->foreignId('realizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ocurrido_en');
            $table->timestamps();

            $table->index('empresa_origen_id');
            $table->index('empresa_destino_id');
            $table->index('ocurrido_en');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traspasos_inventario');
    }
};
