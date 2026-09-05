<?php

namespace App\Acciones;

use App\Enums\EstadoEntrega;
use App\Excepciones\EntregaYaFirmadaException;
use App\Models\AcuseRecepcion;
use App\Models\EntregaUniforme;
use App\Servicios\ServicioAcusePdf;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioFolios;
use App\Soporte\ValidadorFirma;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Confirma la recepción de una entrega: valida la firma manuscrita, congela un
 * snapshot inmutable del contenido, almacena la firma en disco privado, crea el
 * acuse con huellas SHA-256 y marca la entrega como firmada. El PDF se
 * materializa tras confirmar la transacción; si su generación falla, el acuse
 * queda válido sin PDF y puede regenerarse.
 */
class ConfirmarAcuseRecepcion
{
    public function __construct(
        private readonly ValidadorFirma $validadorFirma,
        private readonly ServicioFolios $folios,
        private readonly ServicioAcusePdf $pdf,
        private readonly ServicioAuditoria $auditoria,
    ) {}

    public function ejecutar(
        EntregaUniforme $entrega,
        string $firmaBase64,
        ?int $usuarioOperadorId,
        ?string $ip,
        ?string $userAgent,
    ): AcuseRecepcion {
        if ($entrega->estado !== EstadoEntrega::PendienteFirma) {
            throw EntregaYaFirmadaException::crear();
        }

        if ($entrega->acuse()->exists()) {
            throw EntregaYaFirmadaException::yaTieneAcuse();
        }

        $firma = $this->validadorFirma->validar($firmaBase64);

        $entrega->loadMissing(['detalles', 'colaborador', 'sucursal', 'encargado', 'empresa']);

        $snapshot = $this->construirSnapshot($entrega);
        $hashDocumento = hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $hashFirma = hash('sha256', $firma['binario']);

        $rutaFirma = sprintf('firmas/%d/%s.png', $entrega->empresa_id, Str::uuid());
        Storage::disk('local')->put($rutaFirma, $firma['binario']);

        $acuse = DB::transaction(function () use ($entrega, $snapshot, $hashDocumento, $hashFirma, $rutaFirma, $usuarioOperadorId, $ip, $userAgent): AcuseRecepcion {
            // Recarga con bloqueo para evitar doble firma concurrente.
            $bloqueada = EntregaUniforme::query()->whereKey($entrega->getKey())->lockForUpdate()->first();

            if ($bloqueada === null || $bloqueada->estado !== EstadoEntrega::PendienteFirma) {
                throw EntregaYaFirmadaException::crear();
            }

            $acuse = AcuseRecepcion::query()->create([
                'folio' => $this->folios->siguiente(ServicioFolios::ACUSE),
                'entrega_uniforme_id' => $entrega->getKey(),
                'empresa_id' => $entrega->empresa_id,
                'sucursal_id' => $entrega->sucursal_id,
                'colaborador_id' => $entrega->colaborador_id,
                'usuario_id' => $usuarioOperadorId,
                'nombre_firmante_snapshot' => $entrega->colaborador->nombre_completo,
                'numero_empleado_snapshot' => $entrega->colaborador->numero_empleado,
                'firmado_en' => now(),
                'ip_firma' => $ip,
                'user_agent_firma' => $userAgent !== null ? substr($userAgent, 0, 1000) : null,
                'ruta_firma' => $rutaFirma,
                'ruta_pdf' => null,
                'snapshot_entrega' => $snapshot,
                'hash_documento' => $hashDocumento,
                'hash_firma' => $hashFirma,
            ]);

            $bloqueada->update([
                'estado' => EstadoEntrega::Firmada,
                'confirmada_en' => now(),
            ]);

            $this->auditoria->registrar('acuses', 'firmar', [
                'tipo_entidad' => AcuseRecepcion::class,
                'entidad_id' => $acuse->getKey(),
                'empresa_id' => $entrega->empresa_id,
                'sucursal_id' => $entrega->sucursal_id,
                'descripcion' => 'Acuse '.$acuse->folio.' firmado para la entrega '.$entrega->folio,
            ]);

            return $acuse;
        });

        $this->materializarPdf($acuse);

        return $acuse->refresh();
    }

    public function regenerarPdf(AcuseRecepcion $acuse): AcuseRecepcion
    {
        $this->materializarPdf($acuse);

        return $acuse->refresh();
    }

    private function materializarPdf(AcuseRecepcion $acuse): void
    {
        try {
            $ruta = $this->pdf->generar($acuse);
            $acuse->update(['ruta_pdf' => $ruta]);
        } catch (\Throwable $e) {
            Log::error('No se pudo generar el PDF del acuse '.$acuse->folio, ['excepcion' => $e->getMessage()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function construirSnapshot(EntregaUniforme $entrega): array
    {
        return [
            'version' => 1,
            'empresa' => [
                'id' => $entrega->empresa->id,
                'codigo' => $entrega->empresa->codigo,
                'nombre_comercial' => $entrega->empresa->nombre_comercial,
                'razon_social' => $entrega->empresa->razon_social,
                'direccion' => $entrega->empresa->direccion,
                'logo_ruta' => $entrega->empresa->logo_ruta,
                'color_principal' => $entrega->empresa->color_principal,
            ],
            'sucursal' => [
                'id' => $entrega->sucursal->id,
                'codigo' => $entrega->sucursal->codigo,
                'nombre' => $entrega->sucursal->nombre,
            ],
            'colaborador' => [
                'id' => $entrega->colaborador->id,
                'numero_empleado' => $entrega->colaborador->numero_empleado,
                'nombre_completo' => $entrega->colaborador->nombre_completo,
                'puesto' => $entrega->colaborador->puesto,
                'area' => $entrega->colaborador->area,
            ],
            'encargado' => [
                'id' => $entrega->encargado->id,
                'name' => $entrega->encargado->name,
                'email' => $entrega->encargado->email,
            ],
            'entrega' => [
                'id' => $entrega->id,
                'folio' => $entrega->folio,
                'fecha_entrega' => $entrega->fecha_entrega->format('d/m/Y'),
                'notas' => $entrega->notas,
            ],
            'items' => $entrega->detalles->map(fn ($d): array => [
                // 'activo' es la clave vigente; los acuses previos guardaron
                // 'prenda' en su snapshot inmutable y la plantilla lee ambas.
                'activo' => $d->activo_nombre_snapshot,
                'talla' => $d->talla_valor_snapshot,
                'cantidad' => (int) $d->cantidad,
            ])->all(),
            'firmado_en' => now()->toIso8601String(),
        ];
    }
}
