<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Evidencia fotográfica OPCIONAL adjunta a un renglón concreto de una
     * entrega o una devolución (`evidenciable` polimórfico → `DetalleEntrega`
     * / `DetalleDevolucion`). Documenta el estado físico de lo entregado o
     * devuelto; nunca se infiere condición a partir de la imagen.
     *
     * Una única tabla normalizada — jamás columnas dispersas tipo
     * `foto_entrega`, `foto_entrega_2`, `foto_devolucion`… El archivo vive
     * SIEMPRE en disco privado (`disco` = 'local' = storage/app/private); la
     * BD sólo guarda la referencia y los metadatos verificados en el
     * servidor. Sin cascade de borrado: es contenido histórico y los
     * renglones sólo desaparecen si se elimina físicamente la entrega/
     * devolución padre (que usa soft deletes).
     */
    public function up(): void
    {
        Schema::create('evidencias', function (Blueprint $table): void {
            $table->id();
            $table->string('evidenciable_type');
            $table->unsignedBigInteger('evidenciable_id');
            $table->string('disco', 20)->default('local');
            $table->string('ruta');
            $table->string('nombre_original');
            $table->string('mime', 120);
            $table->string('extension', 10);
            $table->unsignedBigInteger('peso_bytes');
            $table->char('hash_sha256', 64);
            $table->string('origen', 20);
            $table->foreignId('subido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['evidenciable_type', 'evidenciable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidencias');
    }
};
