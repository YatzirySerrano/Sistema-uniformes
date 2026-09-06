<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contador de folios GLOBAL por tipo + año (`App\Servicios\ServicioFolios`).
     * `entregas_uniformes.folio` / `acuses_recepcion.folio` /
     * `devoluciones.folio` son únicos a nivel de toda la plataforma (sin
     * código de empresa en el string), por lo que el contador NO puede
     * particionarse por empresa: dos empresas con el mismo primer folio del
     * año producirían el mismo string y violarían la unicidad del documento.
     */
    public function up(): void
    {
        Schema::create('folios', function (Blueprint $table): void {
            $table->id();
            $table->string('tipo', 20);
            $table->unsignedSmallInteger('anio');
            $table->unsignedInteger('consecutivo')->default(0);
            $table->timestamps();

            $table->unique(['tipo', 'anio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folios');
    }
};
