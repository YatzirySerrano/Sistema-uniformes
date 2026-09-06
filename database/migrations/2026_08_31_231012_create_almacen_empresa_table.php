<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Relación N:M: un almacén abastece a varias empresas / razones sociales;
     * el inventario se mantiene separado por empresa dentro del almacén
     * (`saldos_inventario.empresa_id` + `almacen_id`).
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
    }

    public function down(): void
    {
        Schema::dropIfExists('almacen_empresa');
    }
};
