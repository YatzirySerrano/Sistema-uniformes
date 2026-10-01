<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Una ronda de inventario físico ahora es INTEGRAL por empresa: incluye
     * las existencias por cantidad de TODOS los almacenes que la abastecen.
     * Cada renglón debe identificar su almacén para contar, corregir (ajustar
     * el saldo del almacén correcto), exportar y auditar — antes se derivaba de
     * `inventarios_fisicos.almacen_id`, que sólo admitía un almacén por ronda.
     *
     * Aditiva y segura: los renglones existentes toman el almacén de su ronda
     * (el único posible hasta hoy); el índice único pasa a incluir el almacén
     * para que "Camisa M" en el Almacén A y en el B sean renglones distintos.
     */
    public function up(): void
    {
        Schema::table('inventario_fisico_existencias', function (Blueprint $table): void {
            $table->foreignId('almacen_id')->nullable()->after('inventario_fisico_id')
                ->constrained('almacenes')->cascadeOnUpdate()->restrictOnDelete();
        });

        DB::table('inventario_fisico_existencias')->update([
            'almacen_id' => DB::raw('(select almacen_id from inventarios_fisicos where inventarios_fisicos.id = inventario_fisico_existencias.inventario_fisico_id)'),
        ]);

        Schema::table('inventario_fisico_existencias', function (Blueprint $table): void {
            $table->dropUnique('inv_fisico_existencia_unico');
            $table->unique(['inventario_fisico_id', 'almacen_id', 'activo_id', 'talla_ref'], 'inv_fisico_existencia_almacen_unico');
        });
    }

    public function down(): void
    {
        Schema::table('inventario_fisico_existencias', function (Blueprint $table): void {
            $table->dropUnique('inv_fisico_existencia_almacen_unico');
            $table->unique(['inventario_fisico_id', 'activo_id', 'talla_ref'], 'inv_fisico_existencia_unico');
            $table->dropConstrainedForeignId('almacen_id');
        });
    }
};
