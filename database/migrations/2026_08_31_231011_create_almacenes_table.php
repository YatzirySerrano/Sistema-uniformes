<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El almacén NO pertenece a una empresa: abastece a una o varias
     * (N:M vía `almacen_empresa`) y no se relaciona con sucursales. `codigo`
     * es único a nivel plataforma.
     */
    public function up(): void
    {
        Schema::create('almacenes', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre');
            $table->string('codigo', 60)->nullable()->unique();
            $table->text('descripcion')->nullable();
            $table->string('direccion')->nullable();
            $table->string('telefono', 20)->nullable();
            $table->string('correo')->nullable();
            $table->foreignId('responsable_colaborador_id')->nullable()
                ->constrained('colaboradores')->cascadeOnUpdate()->nullOnDelete();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('activo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('almacenes');
    }
};
