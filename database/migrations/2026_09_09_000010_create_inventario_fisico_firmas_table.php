<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Firma de conformidad del encargado al CERRAR una ronda de inventario
     * físico. Una ronda sólo puede finalizarse con la firma manuscrita de
     * quien la realizó + la aceptación explícita de responsabilidad sobre lo
     * registrado.
     *
     * 1:1 con la ronda (`UNIQUE(inventario_fisico_id)`): la fila SÓLO existe si
     * el cierre transaccional terminó bien (si algo falla, se borra el archivo
     * y no queda registro). La imagen vive en disco privado; se guarda su
     * huella SHA-256 y el texto de consentimiento congelado.
     */
    public function up(): void
    {
        Schema::create('inventario_fisico_firmas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventario_fisico_id')->unique()->constrained('inventarios_fisicos')->cascadeOnUpdate()->cascadeOnDelete();

            $table->string('ruta_firma');
            $table->char('hash_firma', 64);
            $table->string('nombre_firmante');
            $table->text('texto_aceptado');
            $table->timestamp('aceptado_en');
            $table->foreignId('firmado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventario_fisico_firmas');
    }
};
