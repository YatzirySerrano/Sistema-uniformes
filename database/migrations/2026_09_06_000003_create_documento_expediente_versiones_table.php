<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Historial append-only de archivos por documento del expediente: nunca
     * se borra ni se sobrescribe una versión. La versión vigente es la de
     * `version` más alto (`DocumentoExpediente::versionActual()`, resuelta
     * con `latestOfMany`) — no hay un puntero `version_actual_id` en el
     * padre para evitar una FK circular entre esta tabla y
     * `documentos_expediente`.
     */
    public function up(): void
    {
        Schema::create('documento_expediente_versiones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('documento_expediente_id')->constrained('documentos_expediente')->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('ruta');
            $table->string('nombre_archivo_original');
            $table->string('mime', 120);
            $table->string('extension', 10);
            $table->unsignedBigInteger('peso_bytes');
            $table->char('hash_sha256', 64);
            $table->text('comentario')->nullable();
            $table->foreignId('subido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['documento_expediente_id', 'version'], 'doc_expediente_version_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documento_expediente_versiones');
    }
};
