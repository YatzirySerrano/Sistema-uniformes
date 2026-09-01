<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acuses_recepcion', function (Blueprint $table): void {
            $table->id();
            $table->string('folio')->unique();
            $table->foreignId('entrega_uniforme_id')->unique()->constrained('entregas_uniformes')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nombre_firmante_snapshot');
            $table->string('numero_empleado_snapshot', 60);
            $table->timestamp('firmado_en');
            $table->string('ip_firma', 45)->nullable();
            $table->text('user_agent_firma')->nullable();
            $table->string('ruta_firma');
            $table->string('ruta_pdf')->nullable();
            $table->json('snapshot_entrega');
            $table->string('hash_documento', 64);
            $table->string('hash_firma', 64);
            $table->timestamps();

            $table->index('empresa_id');
            $table->index('colaborador_id');
            $table->index('firmado_en');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acuses_recepcion');
    }
};
