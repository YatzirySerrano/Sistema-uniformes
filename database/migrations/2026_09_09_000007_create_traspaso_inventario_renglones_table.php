<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Renglón de un traspaso. Para control por cantidad guarda `talla_id` +
     * `cantidad`; para seguimiento individual se crea UN renglón por unidad
     * física (`unidad_activo_id`, `cantidad = 1`) — así se evita una tabla
     * pivote extra y cada unidad transferida queda trazada de forma
     * independiente.
     *
     * `activo_destino_id` se resuelve DENTRO de la transacción del traspaso
     * (homologación: reutiliza el activo equivalente de la empresa destino o
     * crea uno nuevo). `activo_destino_creado` deja constancia de si este
     * traspaso creó ese activo. `movimiento_salida_id` / `movimiento_entrada_id`
     * correlacionan las dos patas reales en `movimientos_inventario`
     * (`nullOnDelete`: si algún día se purgaran movimientos, el renglón
     * histórico del traspaso sobrevive con sus snapshots).
     */
    public function up(): void
    {
        Schema::create('traspaso_inventario_renglones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('traspaso_inventario_id')->constrained('traspasos_inventario')->cascadeOnDelete();
            $table->string('control', 20);
            $table->foreignId('activo_origen_id')->constrained('activos')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('activo_destino_id')->constrained('activos')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('talla_id')->nullable()->constrained('tallas')->nullOnDelete();
            $table->foreignId('unidad_activo_id')->nullable()->constrained('unidades_activo')->nullOnDelete();
            $table->unsignedInteger('cantidad');
            $table->string('activo_origen_nombre_snapshot');
            $table->string('activo_destino_nombre_snapshot');
            $table->string('talla_valor_snapshot', 30)->nullable();
            $table->boolean('activo_destino_creado')->default(false);
            $table->string('unidad_codigo_snapshot', 40)->nullable();
            $table->foreignId('movimiento_salida_id')->nullable()->constrained('movimientos_inventario')->nullOnDelete();
            $table->foreignId('movimiento_entrada_id')->nullable()->constrained('movimientos_inventario')->nullOnDelete();
            $table->timestamps();

            $table->index('traspaso_inventario_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traspaso_inventario_renglones');
    }
};
