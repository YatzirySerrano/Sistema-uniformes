<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Datos técnicos por UNIDAD identificada (1:1 con `unidades_activo`):
     * marca, modelo, IMEI, número telefónico, operador y plan.
     *
     * Tabla lateral (no columnas en `unidades_activo`) porque:
     * - No se pueden añadir columnas a la tabla histórica (regla: sólo
     *   migraciones `create_`).
     * - La inmensa mayoría de unidades identificadas (herramientas, muebles,
     *   equipo diverso) nunca tienen estos campos: mantener la tabla caliente
     *   ligera y el índice UNIQUE de IMEI fuera de ella.
     * - Son seis campos concretos y estables, NO un EAV genérico.
     *
     * IMEI y número se guardan como STRING (identificadores, no cantidades:
     * preservan ceros a la izquierda y formatos futuros). `imei` es UNIQUE
     * tolerando NULL (MySQL/MariaDB permiten varios NULL en un índice único);
     * el número NO es único (una línea puede reasignarse a otro equipo).
     * `imei` es NULLABLE en BD para no bloquear unidades históricas; su
     * obligatoriedad para el perfil Celular vive en el Form Request.
     */
    public function up(): void
    {
        Schema::create('unidad_activo_especificaciones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('unidad_activo_id')
                ->unique()
                ->constrained('unidades_activo')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->string('marca', 120)->nullable();
            $table->string('modelo', 160)->nullable();
            $table->string('imei', 20)->nullable();
            $table->string('numero_telefonico', 30)->nullable();
            $table->string('operador', 80)->nullable();
            $table->string('plan', 200)->nullable();
            $table->timestamps();

            $table->unique('imei', 'unidad_activo_esp_imei_unico');
            $table->index('numero_telefonico', 'unidad_activo_esp_numero_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unidad_activo_especificaciones');
    }
};
