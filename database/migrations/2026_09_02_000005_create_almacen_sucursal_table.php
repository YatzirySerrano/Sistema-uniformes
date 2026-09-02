<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Relación N:M: un almacén abastece a varias sucursales y una sucursal puede
     * ser abastecida por varios almacenes. Deliberadamente NO se usa
     * `sucursales.almacen_id` para no limitar el modelo operativo.
     */
    public function up(): void
    {
        Schema::create('almacen_sucursal', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('almacen_id')->constrained('almacenes')->cascadeOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['almacen_id', 'sucursal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('almacen_sucursal');
    }
};
