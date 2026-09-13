<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Foto OPCIONAL de la unidad de seguimiento individual (1:1) — su
     * identidad física actual, distinta de `evidencias` (que documenta
     * eventos de entrega/devolución, no la unidad en sí). Mismo patrón
     * técnico que `evidencias`: disco privado, MIME/hash verificados en el
     * servidor, nombre generado (nunca el original). Reemplazar/eliminar no
     * toca `codigo`/`public_token`/`estado`/`condicion` de la unidad.
     */
    public function up(): void
    {
        Schema::create('imagenes_unidad_activo', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('unidad_activo_id')->unique()->constrained('unidades_activo')->restrictOnDelete();
            $table->string('disco', 20)->default('local');
            $table->string('ruta');
            $table->string('nombre_original');
            $table->string('mime', 120);
            $table->string('extension', 10);
            $table->unsignedBigInteger('peso_bytes');
            $table->char('hash_sha256', 64);
            $table->foreignId('subido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imagenes_unidad_activo');
    }
};
