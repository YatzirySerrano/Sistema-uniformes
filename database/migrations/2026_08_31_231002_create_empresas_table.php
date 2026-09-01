<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresas', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo')->unique();
            $table->string('nombre_comercial');
            $table->string('razon_social')->nullable();
            $table->string('rfc', 20)->nullable();
            $table->string('logo_ruta')->nullable();
            $table->string('telefono', 40)->nullable();
            $table->string('correo')->nullable();
            $table->string('direccion')->nullable();
            $table->string('color_principal', 9)->default('#2563eb');
            $table->string('color_secundario', 9)->default('#1e40af');
            $table->string('color_acento', 9)->default('#f59e0b');
            $table->boolean('activa')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresas');
    }
};
