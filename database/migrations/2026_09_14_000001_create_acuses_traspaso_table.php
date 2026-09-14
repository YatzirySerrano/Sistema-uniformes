<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Evidencia inmutable de la firma obligatoria de un traspaso de
     * inventario — 1:1 con `traspasos_inventario` (un traspaso sólo existe ya
     * firmado; no hay estado "pendiente de firma" para traspasos). UNA sola
     * firma: la del responsable que confirma el traspaso (no hay "quien
     * entrega"/"quien recibe" como en Entregas/Devoluciones).
     *
     * Reutiliza el folio de `traspasos_inventario.folio` (TRA-...) para el
     * comprobante — no se genera un segundo folio para el acuse, ya que la FK
     * única `traspaso_inventario_id` ya garantiza el 1:1 y ambos documentos
     * son, en la práctica, el mismo trámite.
     *
     * `empresa_origen_id`/`empresa_destino_id` se denormalizan desde el
     * traspaso (igual que `acuses_devolucion.empresa_id`) para que la Policy
     * autorice sin tener que cargar la relación cada vez.
     */
    public function up(): void
    {
        Schema::create('acuses_traspaso', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('traspaso_inventario_id')->unique()->constrained('traspasos_inventario')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('empresa_origen_id')->constrained('empresas')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('empresa_destino_id')->constrained('empresas')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('firmado_por')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('nombre_firmante_snapshot');
            $table->string('ruta_firma');
            $table->string('hash_firma', 64);
            $table->timestamp('firmado_en');
            $table->string('ip_firma', 45)->nullable();
            $table->text('user_agent_firma')->nullable();
            $table->json('snapshot_traspaso');
            $table->string('hash_documento', 64);
            $table->string('ruta_pdf')->nullable();
            $table->timestamps();

            $table->index('empresa_origen_id');
            $table->index('empresa_destino_id');
            $table->index('firmado_en');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acuses_traspaso');
    }
};
