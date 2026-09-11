<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Empresa de ORIGEN de cada versión de un documento del expediente
     * (1:1 con `documento_expediente_versiones`).
     *
     * `documento_expediente_versiones` es la entidad "archivo/versión"
     * (append-only, inmutable). El slot padre `documentos_expediente` NO puede
     * representar la empresa de origen porque un mismo slot ("Contratos de
     * Juan") puede acumular una versión subida bajo la empresa A y otra bajo la
     * empresa B tras un traslado. Para las categorías EMPRESARIALES
     * (`CategoriaDocumentoExpediente::viajaConLaPersona() === false`) la
     * visibilidad de cada versión queda ligada a la empresa registrada aquí:
     * un usuario que sólo accede a la empresa B no ve las versiones subidas
     * bajo la empresa A, y viceversa (Admin/Superadmin ven todas).
     *
     * No se puede añadir la columna a la tabla histórica (regla: sólo
     * migraciones `create_`), de ahí esta tabla lateral.
     *
     * BACKFILL: esta ronda es la que INTRODUCE los traslados entre empresas, así
     * que ninguna versión existente precede a un traslado. Se asocia cada
     * versión histórica a la empresa ACTUAL del colaborador dueño — es la única
     * señal disponible y es correcta bajo esa premisa (no se inventa historia).
     */
    public function up(): void
    {
        Schema::create('documento_expediente_version_empresa', function (Blueprint $table): void {
            $table->id();
            // Nombres de constraint/índice EXPLÍCITOS y cortos: el nombre
            // autogenerado de la FK supera el límite de 64 caracteres de
            // MySQL/MariaDB.
            $table->unsignedBigInteger('documento_expediente_version_id');
            $table->unsignedBigInteger('empresa_id');
            $table->timestamps();

            $table->unique('documento_expediente_version_id', 'dev_empresa_version_unico');
            $table->index('empresa_id', 'dev_empresa_empresa_idx');

            $table->foreign('documento_expediente_version_id', 'dev_empresa_version_fk')
                ->references('id')->on('documento_expediente_versiones')
                ->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreign('empresa_id', 'dev_empresa_empresa_fk')
                ->references('id')->on('empresas')
                ->cascadeOnUpdate()->restrictOnDelete();
        });

        DB::table('documento_expediente_versiones as v')
            ->join('documentos_expediente as d', 'd.id', '=', 'v.documento_expediente_id')
            ->join('colaboradores as c', 'c.id', '=', 'd.colaborador_id')
            ->select('v.id as version_id', 'c.empresa_id')
            ->orderBy('v.id')
            ->chunk(500, function ($filas): void {
                $ahora = now();
                $insertar = [];

                foreach ($filas as $fila) {
                    $insertar[] = [
                        'documento_expediente_version_id' => $fila->version_id,
                        'empresa_id' => $fila->empresa_id,
                        'created_at' => $ahora,
                        'updated_at' => $ahora,
                    ];
                }

                if ($insertar !== []) {
                    DB::table('documento_expediente_version_empresa')->insert($insertar);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('documento_expediente_version_empresa');
    }
};
