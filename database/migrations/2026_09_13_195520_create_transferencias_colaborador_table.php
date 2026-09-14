<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Historial estructurado y append-only de cada transferencia de empresa
     * de un colaborador (ver `App\Acciones\CambiarEmpresaColaborador`). Se
     * escribe una fila por transferencia, con los IDs reales de origen y
     * destino, para poder reconstruir periodos con fechas exactas — la
     * auditoría genérica (`bitacora_auditoria`) sólo guarda nombres legibles.
     */
    public function up(): void
    {
        Schema::create('transferencias_colaborador', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('empresa_origen_id')->constrained('empresas')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('empresa_destino_id')->constrained('empresas')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('sucursal_origen_id')->nullable()->constrained('sucursales')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('sucursal_destino_id')->nullable()->constrained('sucursales')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('area_origen_id')->nullable()->constrained('areas')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('area_destino_id')->nullable()->constrained('areas')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('servicio_origen_id')->nullable()->constrained('servicios')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('servicio_destino_id')->nullable()->constrained('servicios')->cascadeOnUpdate()->nullOnDelete();
            $table->string('numero_empleado_anterior')->nullable();
            $table->string('numero_empleado_nuevo');
            $table->text('motivo')->nullable();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ocurrido_en');
            $table->timestamp('created_at')->nullable();

            $table->index(['colaborador_id', 'ocurrido_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transferencias_colaborador');
    }
};
