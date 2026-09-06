<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Slot" estable de un documento del expediente (identidad + categoría +
     * nombre). El archivo físico vigente vive en
     * `documento_expediente_versiones` (versión de número más alto); esta
     * tabla nunca guarda una ruta de archivo directamente para no duplicar la
     * fuente de verdad del historial.
     */
    public function up(): void
    {
        Schema::create('documentos_expediente', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('categoria', 40);
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->boolean('activo')->default(true);
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['colaborador_id', 'categoria']);
            $table->index(['colaborador_id', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos_expediente');
    }
};
