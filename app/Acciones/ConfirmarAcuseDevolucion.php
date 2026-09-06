<?php

namespace App\Acciones;

use App\Enums\EstadoDevolucion;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\AcuseDevolucion;
use App\Models\Devolucion;
use App\Servicios\ServicioAcuseDevolucionPdf;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioFolios;
use App\Soporte\ValidadorFirma;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Confirma una devolución: valida AMBAS firmas manuscritas (quien devuelve y
 * el encargado que recibe la devolución), exige que el encargado haya
 * aceptado explícitamente el texto de responsabilidad, congela un snapshot
 * inmutable, almacena las firmas en disco privado, crea el acuse con huellas
 * SHA-256 y marca la devolución como confirmada. El inventario YA se
 * restauró al registrar la devolución (`RegistrarDevolucion`) — confirmar no
 * mueve stock, sólo cierra el ciclo documental/legal, igual criterio que
 * usan las Entregas.
 */
class ConfirmarAcuseDevolucion
{
    /**
     * Texto de responsabilidad vigente para quien recibe la devolución. Se
     * congela en `texto_aceptado_snapshot` en el momento de la firma.
     */
    public const TEXTO_CONSENTIMIENTO = 'He leído el detalle de esta devolución y confirmo que recibo los activos descritos bajo mi responsabilidad.';

    public function __construct(
        private readonly ValidadorFirma $validadorFirma,
        private readonly ServicioFolios $folios,
        private readonly ServicioAcuseDevolucionPdf $pdf,
        private readonly ServicioAuditoria $auditoria,
    ) {}

    public function ejecutar(
        Devolucion $devolucion,
        string $firmaColaboradorBase64,
        string $firmaOperadorBase64,
        bool $aceptacionOperador,
        ?int $usuarioOperadorId,
        ?string $ip,
        ?string $userAgent,
    ): AcuseDevolucion {
        if ($devolucion->estado !== EstadoDevolucion::PendienteFirma) {
            throw new ExcepcionDeNegocioSimple('Esta devolución ya fue confirmada.');
        }

        if ($devolucion->acuse()->exists()) {
            throw new ExcepcionDeNegocioSimple('Esta devolución ya cuenta con un acuse firmado.');
        }

        if (! $aceptacionOperador) {
            throw new ExcepcionDeNegocioSimple('Debes confirmar que aceptas la responsabilidad antes de firmar.');
        }

        $firmaColaborador = $this->validadorFirma->validar($firmaColaboradorBase64);
        $firmaOperador = $this->validadorFirma->validar($firmaOperadorBase64);

        $devolucion->loadMissing(['detalles.activo', 'detalles.talla', 'detalles.unidadActivo', 'colaborador', 'sucursal', 'almacen', 'empresa', 'registradaPor']);

        $snapshot = $this->construirSnapshot($devolucion);
        $hashDocumento = hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $hashFirmaColaborador = hash('sha256', $firmaColaborador['binario']);
        $hashFirmaOperador = hash('sha256', $firmaOperador['binario']);

        $rutaFirmaColaborador = sprintf('firmas/%d/%s.png', $devolucion->empresa_id, Str::uuid());
        $rutaFirmaOperador = sprintf('firmas/%d/%s.png', $devolucion->empresa_id, Str::uuid());
        Storage::disk('local')->put($rutaFirmaColaborador, $firmaColaborador['binario']);
        Storage::disk('local')->put($rutaFirmaOperador, $firmaOperador['binario']);

        try {
            $acuse = DB::transaction(function () use (
                $devolucion, $snapshot, $hashDocumento, $hashFirmaColaborador, $hashFirmaOperador,
                $rutaFirmaColaborador, $rutaFirmaOperador, $usuarioOperadorId, $ip, $userAgent,
            ): AcuseDevolucion {
                // Recarga con bloqueo para evitar doble confirmación concurrente.
                $bloqueada = Devolucion::query()->whereKey($devolucion->getKey())->lockForUpdate()->first();

                if ($bloqueada === null || $bloqueada->estado !== EstadoDevolucion::PendienteFirma) {
                    throw new ExcepcionDeNegocioSimple('Esta devolución ya fue confirmada.');
                }

                $acuse = AcuseDevolucion::query()->create([
                    'folio' => $this->folios->siguiente(ServicioFolios::ACUSE_DEVOLUCION),
                    'devolucion_id' => $devolucion->getKey(),
                    'empresa_id' => $devolucion->empresa_id,
                    'sucursal_id' => $devolucion->sucursal_id,
                    'colaborador_id' => $devolucion->colaborador_id,
                    'usuario_id' => $usuarioOperadorId,
                    'nombre_firmante_snapshot' => $devolucion->colaborador->nombre_completo,
                    'numero_empleado_snapshot' => $devolucion->colaborador->numero_empleado,
                    'ruta_firma' => $rutaFirmaColaborador,
                    'hash_firma' => $hashFirmaColaborador,
                    'nombre_firmante_operador_snapshot' => $devolucion->registradaPor?->name,
                    'ruta_firma_operador' => $rutaFirmaOperador,
                    'hash_firma_operador' => $hashFirmaOperador,
                    'aceptacion_titular' => true,
                    'texto_aceptado_snapshot' => self::TEXTO_CONSENTIMIENTO,
                    'aceptado_en' => now(),
                    'firmado_en' => now(),
                    'ip_firma' => $ip,
                    'user_agent_firma' => $userAgent !== null ? substr($userAgent, 0, 1000) : null,
                    'ruta_pdf' => null,
                    'snapshot_devolucion' => $snapshot,
                    'hash_documento' => $hashDocumento,
                ]);

                $bloqueada->update([
                    'estado' => EstadoDevolucion::Confirmada,
                    'confirmada_en' => now(),
                ]);

                $this->auditoria->registrar('devoluciones', 'confirmar', [
                    'tipo_entidad' => AcuseDevolucion::class,
                    'entidad_id' => $acuse->getKey(),
                    'empresa_id' => $devolucion->empresa_id,
                    'sucursal_id' => $devolucion->sucursal_id,
                    'descripcion' => 'Acuse '.$acuse->folio.' firmado (colaborador y encargado) para la devolución '.$devolucion->folio,
                ]);

                return $acuse;
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete([$rutaFirmaColaborador, $rutaFirmaOperador]);

            throw $e;
        }

        $this->materializarPdf($acuse);

        return $acuse->refresh();
    }

    public function regenerarPdf(AcuseDevolucion $acuse): AcuseDevolucion
    {
        $this->materializarPdf($acuse);

        return $acuse->refresh();
    }

    private function materializarPdf(AcuseDevolucion $acuse): void
    {
        try {
            $ruta = $this->pdf->generar($acuse);
            $acuse->update(['ruta_pdf' => $ruta]);
        } catch (Throwable $e) {
            Log::error('No se pudo generar el PDF del acuse de devolución '.$acuse->folio, ['excepcion' => $e->getMessage()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function construirSnapshot(Devolucion $devolucion): array
    {
        return [
            'version' => 1,
            'empresa' => [
                'id' => $devolucion->empresa->id,
                'codigo' => $devolucion->empresa->codigo,
                'nombre_comercial' => $devolucion->empresa->nombre_comercial,
                'razon_social' => $devolucion->empresa->razon_social,
                'direccion' => $devolucion->empresa->direccion,
                'logo_ruta' => $devolucion->empresa->logo_ruta,
                'color_principal' => $devolucion->empresa->color_principal,
            ],
            'sucursal' => [
                'id' => $devolucion->sucursal->id,
                'codigo' => $devolucion->sucursal->codigo,
                'nombre' => $devolucion->sucursal->nombre,
            ],
            'colaborador' => [
                'id' => $devolucion->colaborador->id,
                'numero_empleado' => $devolucion->colaborador->numero_empleado,
                'nombre_completo' => $devolucion->colaborador->nombre_completo,
                'puesto' => $devolucion->colaborador->puesto,
                'area' => $devolucion->colaborador->area,
            ],
            'operador' => [
                'id' => $devolucion->registradaPor?->id,
                'name' => $devolucion->registradaPor?->name,
                'email' => $devolucion->registradaPor?->email,
            ],
            'devolucion' => [
                'id' => $devolucion->id,
                'folio' => $devolucion->folio,
                'fecha' => $devolucion->fecha->format('d/m/Y'),
                'motivo' => $devolucion->motivo,
                'notas' => $devolucion->notas,
            ],
            'items' => $devolucion->detalles->map(fn ($d): array => [
                'activo' => $d->activo?->nombre,
                'talla' => $d->talla?->valor,
                'cantidad' => (int) $d->cantidad,
                'condicion' => ($d->unidad_activo_id !== null ? $d->condicion_unidad : $d->condicion)?->etiqueta(),
            ])->all(),
            'firmado_en' => now()->toIso8601String(),
        ];
    }
}
