<?php

namespace App\Acciones;

use App\Enums\EstadoEntrega;
use App\Excepciones\EntregaYaFirmadaException;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Mail\ComprobanteEntregaMail;
use App\Models\AcuseRecepcion;
use App\Models\EntregaUniforme;
use App\Servicios\ServicioAcusePdf;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioFolios;
use App\Soporte\ValidadorFirma;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Confirma la recepción de una entrega: valida AMBAS firmas manuscritas (la
 * del colaborador que recibe y la del encargado que entrega), exige que el
 * colaborador haya aceptado explícitamente el texto de responsabilidad,
 * congela un snapshot inmutable del contenido, almacena las firmas en disco
 * privado, crea el acuse con huellas SHA-256 y marca la entrega como
 * firmada. El PDF se materializa tras confirmar la transacción; si su
 * generación falla, el acuse queda válido sin PDF y puede regenerarse.
 */
class ConfirmarAcuseRecepcion
{
    /**
     * Texto de responsabilidad vigente. Se congela en `texto_aceptado_snapshot`
     * en el momento de la firma — cambiar este texto a futuro NO reescribe
     * acuses ya firmados.
     */
    public const TEXTO_CONSENTIMIENTO = 'He leído la información anterior y confirmo que la recibo bajo mi responsabilidad.';

    public function __construct(
        private readonly ValidadorFirma $validadorFirma,
        private readonly ServicioFolios $folios,
        private readonly ServicioAcusePdf $pdf,
        private readonly ServicioAuditoria $auditoria,
    ) {}

    public function ejecutar(
        EntregaUniforme $entrega,
        string $firmaColaboradorBase64,
        string $firmaOperadorBase64,
        bool $aceptacionTitular,
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

        if (! $aceptacionTitular) {
            throw new ExcepcionDeNegocioSimple('Debes confirmar que aceptas la responsabilidad antes de firmar.');
        }

        $firmaColaborador = $this->validadorFirma->validar($firmaColaboradorBase64);
        $firmaOperador = $this->validadorFirma->validar($firmaOperadorBase64);

        $entrega->loadMissing(['detalles', 'colaborador', 'sucursal', 'encargado', 'empresa']);

        $snapshot = $this->construirSnapshot($entrega);
        $hashDocumento = hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $hashFirmaColaborador = hash('sha256', $firmaColaborador['binario']);
        $hashFirmaOperador = hash('sha256', $firmaOperador['binario']);

        $rutaFirmaColaborador = sprintf('firmas/%d/%s.png', $entrega->empresa_id, Str::uuid());
        $rutaFirmaOperador = sprintf('firmas/%d/%s.png', $entrega->empresa_id, Str::uuid());
        Storage::disk('local')->put($rutaFirmaColaborador, $firmaColaborador['binario']);
        Storage::disk('local')->put($rutaFirmaOperador, $firmaOperador['binario']);

        try {
            $acuse = DB::transaction(function () use (
                $entrega, $snapshot, $hashDocumento, $hashFirmaColaborador, $hashFirmaOperador,
                $rutaFirmaColaborador, $rutaFirmaOperador, $usuarioOperadorId, $ip, $userAgent,
            ): AcuseRecepcion {
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
                    'ruta_firma' => $rutaFirmaColaborador,
                    'nombre_firmante_operador_snapshot' => $entrega->encargado?->name,
                    'ruta_firma_operador' => $rutaFirmaOperador,
                    'hash_firma_operador' => $hashFirmaOperador,
                    'aceptacion_titular' => true,
                    'texto_aceptado_snapshot' => self::TEXTO_CONSENTIMIENTO,
                    'aceptado_en' => now(),
                    'ruta_pdf' => null,
                    'snapshot_entrega' => $snapshot,
                    'hash_documento' => $hashDocumento,
                    'hash_firma' => $hashFirmaColaborador,
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
                    'descripcion' => 'Acuse '.$acuse->folio.' firmado (colaborador y encargado) para la entrega '.$entrega->folio,
                ]);

                return $acuse;
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete([$rutaFirmaColaborador, $rutaFirmaOperador]);

            throw $e;
        }

        $this->materializarPdf($acuse);

        $acuse = $acuse->refresh();

        $this->enviarComprobante($acuse, $entrega);

        return $acuse;
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
        } catch (Throwable $e) {
            Log::error('No se pudo generar el PDF del acuse '.$acuse->folio, ['excepcion' => $e->getMessage()]);
        }
    }

    /**
     * Notifica a AMBAS partes que la entrega quedó confirmada: el
     * ENCARGADO que realizó la entrega y el COLABORADOR que la recibió y
     * firmó (sólo si tiene correo registrado). Adjunta el PDF del acuse si
     * ya se materializó.
     *
     * Se ejecuta SIEMPRE fuera de la transacción de negocio y nunca lanza:
     * si el correo — o incluso la cola — fallara, la entrega firmada, su
     * acuse, las firmas y el inventario permanecen intactos (sólo se
     * registra el fallo en el log).
     *
     * Idempotencia: sólo se llega aquí una vez por confirmación (la
     * transición `PendienteFirma → Firmada` es irreversible y está protegida
     * con `lockForUpdate`). Ningún endpoint de consulta, descarga o
     * regeneración de PDF pasa por este método. El candado en caché es una
     * segunda barrera ante una doble invocación por concurrencia.
     */
    private function enviarComprobante(AcuseRecepcion $acuse, EntregaUniforme $entrega): void
    {
        if (! Cache::add('acuse-recepcion:correo:'.$acuse->getKey(), true, now()->addDays(7))) {
            return;
        }

        $destinatarios = collect([
            $entrega->encargado?->email,
            $entrega->colaborador?->correo,
        ])
            ->filter(fn (?string $correo): bool => is_string($correo) && filter_var($correo, FILTER_VALIDATE_EMAIL) !== false)
            ->map(fn (string $correo): string => mb_strtolower(trim($correo)))
            ->unique()
            ->values();

        if ($destinatarios->isEmpty()) {
            return;
        }

        try {
            Mail::to($destinatarios->all())->queue(new ComprobanteEntregaMail($acuse));
        } catch (Throwable $e) {
            Log::error('No se pudo encolar el comprobante de la entrega '.$entrega->folio, ['excepcion' => $e->getMessage()]);
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
