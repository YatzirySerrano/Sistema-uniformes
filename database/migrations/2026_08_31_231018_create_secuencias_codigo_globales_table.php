<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contador atómico race-safe para catálogos SIN dimensión de empresa
     * (Almacén, Tipo de activo): `ServicioGeneradorCodigosGlobal` bloquea la
     * fila con `lockForUpdate()` antes de incrementar. Ámbitos: `almacen`,
     * `tipo_activo`.
     */
    public function up(): void
    {
        Schema::create('secuencias_codigo_globales', function (Blueprint $table): void {
            $table->id();
            $table->string('ambito', 40)->unique();
            $table->unsignedBigInteger('ultimo_valor')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secuencias_codigo_globales');
    }
};
