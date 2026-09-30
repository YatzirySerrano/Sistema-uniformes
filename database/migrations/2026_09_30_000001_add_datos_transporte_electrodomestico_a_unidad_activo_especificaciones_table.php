<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Datos técnicos de los perfiles Transporte y Electrodoméstico en la MISMA
     * tabla lateral 1:1 de especificaciones por unidad (no una segunda
     * arquitectura de atributos). Todas nullable: su obligatoriedad depende
     * del perfil y vive en `PerfilTecnicoUnidad` + Form Requests; las filas
     * existentes no se tocan.
     *
     * - `numero_serie`: NIV / VIN / número de serie del fabricante (vehículo
     *   o electrodoméstico). No es único a propósito: el sistema no puede
     *   garantizar que series de distintos fabricantes no coincidan.
     * - `placas`: indexada para búsqueda, no única (pueden reasignarse).
     */
    public function up(): void
    {
        Schema::table('unidad_activo_especificaciones', function (Blueprint $table): void {
            $table->string('clase_vehiculo', 20)->nullable()->after('plan');
            $table->unsignedSmallInteger('anio')->nullable()->after('clase_vehiculo');
            $table->string('color', 60)->nullable()->after('anio');
            $table->string('placas', 15)->nullable()->after('color');
            $table->string('numero_serie', 60)->nullable()->after('placas');

            $table->index('placas', 'unidad_activo_esp_placas_idx');
            $table->index('numero_serie', 'unidad_activo_esp_serie_idx');
        });
    }

    public function down(): void
    {
        Schema::table('unidad_activo_especificaciones', function (Blueprint $table): void {
            $table->dropIndex('unidad_activo_esp_placas_idx');
            $table->dropIndex('unidad_activo_esp_serie_idx');
            $table->dropColumn(['clase_vehiculo', 'anio', 'color', 'placas', 'numero_serie']);
        });
    }
};
