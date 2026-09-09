<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RONDA de inventario físico por escaneo de QR: un corte puntual para
     * comparar lo que se encuentra físicamente contra lo que el sistema dice
     * de las unidades de seguimiento individual de UNA empresa.
     *
     * Es un módulo de VERIFICACIÓN: nunca mueve stock, ni cambia almacén,
     * asignación, condición ni estado — sólo informa diferencias.
     *
     * El "universo esperado" se congela al iniciar la ronda (snapshot lógico
     * en `inventario_fisico_unidades`), NO se recalcula: una unidad creada
     * después de iniciar aparece como "no esperada", nunca infla el total.
     *
     * `folio` (INVF-AAAA-000001) lo genera `App\Servicios\ServicioFolios`
     * (contador global por tipo+año, ya usado por entregas/devoluciones).
     * `almacen_id` es el alcance opcional: nulo = toda la empresa; con valor =
     * sólo las unidades cuyo almacén "de casa" es ése (una unidad asignada a
     * un colaborador conserva su `almacen_id`, así que no se excluye).
     *
     * Sin `deleted_at`: una ronda es un registro histórico permanente.
     */
    public function up(): void
    {
        Schema::create('inventarios_fisicos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('almacen_id')->nullable()->constrained('almacenes')->nullOnDelete();

            $table->string('folio', 30)->unique();
            $table->string('nombre');
            $table->string('estado', 20)->default('en_proceso');
            $table->text('observaciones')->nullable();

            $table->timestamp('finalizado_en')->nullable();
            $table->timestamps();

            $table->index(['empresa_id', 'estado']);
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventarios_fisicos');
    }
};
