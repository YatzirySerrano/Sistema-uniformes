<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Evidencia inmutable de una devolución confirmada — mismo patrón que
     * `acuses_recepcion`, pero con dos firmas desde el inicio: quien devuelve
     * (colaborador, columnas base) y el encargado que recibe la devolución
     * (columnas `_operador`). El consentimiento explícito lo firma quien
     * recibe la custodia del bien devuelto: el encargado.
     */
    public function up(): void
    {
        Schema::create('acuses_devolucion', function (Blueprint $table): void {
            $table->id();
            $table->string('folio')->unique();
            $table->foreignId('devolucion_id')->unique()->constrained('devoluciones')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nombre_firmante_snapshot');
            $table->string('numero_empleado_snapshot', 60);
            $table->string('ruta_firma');
            $table->string('hash_firma', 64);
            $table->string('nombre_firmante_operador_snapshot')->nullable();
            $table->string('ruta_firma_operador')->nullable();
            $table->string('hash_firma_operador', 64)->nullable();
            $table->boolean('aceptacion_titular')->default(false);
            $table->text('texto_aceptado_snapshot')->nullable();
            $table->timestamp('aceptado_en')->nullable();
            $table->timestamp('firmado_en');
            $table->string('ip_firma', 45)->nullable();
            $table->text('user_agent_firma')->nullable();
            $table->string('ruta_pdf')->nullable();
            $table->json('snapshot_devolucion');
            $table->string('hash_documento', 64);
            $table->timestamps();

            $table->index('empresa_id');
            $table->index('colaborador_id');
            $table->index('firmado_en');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acuses_devolucion');
    }
};
