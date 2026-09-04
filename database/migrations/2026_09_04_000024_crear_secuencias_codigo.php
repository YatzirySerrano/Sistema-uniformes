<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contador atómico por empresa + ámbito, usado por
     * `App\Soporte\ServicioGeneradorCodigos` para generar códigos internos
     * únicos y race-safe (nunca `MAX(id)+1` sin protección): la fila se
     * bloquea con `lockForUpdate()` dentro de una transacción antes de
     * incrementar. `ambito` permite reutilizar la misma tabla para otros
     * contadores futuros (hoy sólo `unidad_activo`).
     *
     * Forward-only. Nace vacía; se puebla bajo demanda.
     */
    public function up(): void
    {
        Schema::create('secuencias_codigo', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('ambito', 40);
            $table->unsignedBigInteger('ultimo_valor')->default(0);
            $table->timestamps();

            $table->unique(['empresa_id', 'ambito']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secuencias_codigo');
    }
};
