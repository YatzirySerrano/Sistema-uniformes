<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catálogo de variantes/tallas **de plataforma** (S, M, 32, 36R,
     * Unitalla…). Global: un mismo valor es una sola fila reutilizada por
     * todas las empresas; el stock sigue separado por
     * `empresa + almacén + activo + talla`. "Sin variante" es
     * `talla_id = NULL` en el inventario (no existe talla comodín).
     */
    public function up(): void
    {
        Schema::create('tallas', function (Blueprint $table): void {
            $table->id();
            $table->string('valor', 30);
            $table->string('valor_normalizado')->default('');
            $table->unsignedInteger('orden')->default(0);
            $table->boolean('activa')->default(true);
            $table->timestamps();

            $table->unique('valor_normalizado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tallas');
    }
};
