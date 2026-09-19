<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Persiste que las diferencias detectadas por una ronda de inventario
     * físico YA fueron aplicadas en bloque a `saldos_inventario`
     * (`App\Acciones\AplicarCorreccionesInventarioFisico`). El lote es
     * SIEMPRE todo-o-nada, así que un par de columnas a nivel de RONDA basta
     * — no hace falta una tabla de aplicación aparte: la trazabilidad por
     * renglón ya queda en `movimientos_inventario` (`referencia_tipo =
     * App\Models\InventarioFisico::class`, `referencia_id` = esta ronda),
     * igual que ya hacen Traspasos/Entregas/Devoluciones.
     *
     * Nulo (`correcciones_aplicadas_en IS NULL`) = todavía no se aplicaron;
     * es el valor por defecto tanto para rondas nuevas como para TODAS las
     * rondas históricas ya `Finalizado` — nunca se asume que una ronda
     * anterior a este cambio ya corrigió el inventario.
     */
    public function up(): void
    {
        Schema::table('inventarios_fisicos', function (Blueprint $table): void {
            $table->timestamp('correcciones_aplicadas_en')->nullable()->after('finalizado_en');
            $table->foreignId('correcciones_aplicadas_por')->nullable()->after('correcciones_aplicadas_en')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventarios_fisicos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('correcciones_aplicadas_por');
            $table->dropColumn('correcciones_aplicadas_en');
        });
    }
};
