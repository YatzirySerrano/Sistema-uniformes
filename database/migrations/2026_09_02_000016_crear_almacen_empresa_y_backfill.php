<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Arquitectura definitiva: un ALMACÉN abastece a una o varias EMPRESAS /
     * razones sociales (N:M). Sustituye a `almacenes.empresa_id` como fuente de
     * verdad. El inventario sigue separado por empresa dentro del almacén
     * (`saldos_inventario.empresa_id` + `almacen_id`).
     *
     * Esta migración es aditiva: crea la pivote y traslada las relaciones
     * existentes. La columna `almacenes.empresa_id` se elimina en la migración
     * `..._000018` una vez que todo el código usa la pivote.
     */
    public function up(): void
    {
        Schema::create('almacen_empresa', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('almacen_id')->constrained('almacenes')->cascadeOnDelete();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['almacen_id', 'empresa_id']);
        });

        // Backfill: cada almacén conserva su empresa actual como empresa abastecida.
        $ahora = now();

        DB::table('almacenes')
            ->select('id', 'empresa_id')
            ->whereNotNull('empresa_id')
            ->orderBy('id')
            ->chunkById(500, function ($almacenes) use ($ahora): void {
                $filas = [];

                foreach ($almacenes as $almacen) {
                    $filas[] = [
                        'almacen_id' => $almacen->id,
                        'empresa_id' => $almacen->empresa_id,
                        'created_at' => $ahora,
                        'updated_at' => $ahora,
                    ];
                }

                if ($filas !== []) {
                    DB::table('almacen_empresa')->insertOrIgnore($filas);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('almacen_empresa');
    }
};
