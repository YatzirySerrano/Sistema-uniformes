<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fase 10 — personalización visual GLOBAL de la instancia (no por
     * empresa). Fila única (`id = 1`, ver `App\Models\ConfiguracionSistema::actual()`).
     * Reemplaza la idea de "colores del sistema por Empresa" (branding de
     * `empresas.color_principal/color_secundario/color_acento`, que se deja
     * de usar para theming pero no se borra: sigue disponible como dato
     * administrativo/decorativo de esa empresa).
     */
    public function up(): void
    {
        Schema::create('configuracion_sistema', function (Blueprint $table): void {
            $table->id();
            $table->string('color_principal', 7);
            $table->string('color_hover_principal', 7);
            $table->string('color_texto_boton_principal', 7);
            $table->string('fondo_general', 7);
            $table->string('fondo_tarjetas', 7);
            $table->string('fondo_sidebar', 7);
            $table->string('color_secundario', 7)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracion_sistema');
    }
};
