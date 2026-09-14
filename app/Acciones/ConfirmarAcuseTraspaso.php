<?php

namespace App\Acciones;

use App\Models\AcuseTraspaso;
use App\Models\TraspasoInventario;
use App\Models\TraspasoRenglon;
use App\Models\User;
use App\Servicios\ServicioAcuseTraspasoPdf;
use App\Servicios\ServicioAuditoria;
use App\Soporte\ValidadorFirma;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Crea la evidencia inmutable de la firma obligatoria de un traspaso: UNA
 * sola firma (el responsable que confirma), congela un snapshot del
 * traspaso ya creado (con sus renglones), calcula las huellas SHA-256 y crea
 * el `AcuseTraspaso`. Reutiliza el folio del propio traspaso (TRA-...) — no
 * genera uno independiente.
 *
 * `confirmarEnTransaccion()` NO abre `DB::transaction`: asume que ya hay una
 * activa (la abre `RegistrarTraspasoFirmado`, que también crea el traspaso
 * dentro de la MISMA transacción — nunca queda un traspaso sin firma ni una
 * firma sin traspaso). La firma ya debe estar validada y escrita en disco
 * ANTES de llamar aquí (mismo patrón "un archivo → un dueño" que
 * Entregas/Devoluciones: quien escribe el archivo fuera de la transacción es
 * también quien lo borra si algo falla).
 */
class ConfirmarAcuseTraspaso
{
    public function __construct(
        private readonly ValidadorFirma $validadorFirma,
        private readonly ServicioAcuseTraspasoPdf $pdf,
        private readonly ServicioAuditoria $auditoria,
    ) {}

    /**
     * @return array{binario: string, mime: string, ancho: int, alto: int}
     */
    public function validarFirma(string $firmaBase64): array
    {
        return $this->validadorFirma->validar($firmaBase64);
    }

    public function confirmarEnTransaccion(
        TraspasoInventario $traspaso,
        string $rutaFirma,
        string $hashFirma,
        User $firmante,
        ?string $ip,
        ?string $userAgent,
    ): AcuseTraspaso {
        $traspaso->loadMissing([
            'renglones', 'empresaOrigen', 'empresaDestino', 'almacenOrigen', 'almacenDestino', 'realizadoPor',
        ]);

        $snapshot = $this->construirSnapshot($traspaso, $firmante);
        $hashDocumento = hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $acuse = AcuseTraspaso::query()->create([
            'traspaso_inventario_id' => $traspaso->getKey(),
            'empresa_origen_id' => $traspaso->empresa_origen_id,
            'empresa_destino_id' => $traspaso->empresa_destino_id,
            'firmado_por' => $firmante->getKey(),
            'nombre_firmante_snapshot' => $firmante->name,
            'ruta_firma' => $rutaFirma,
            'hash_firma' => $hashFirma,
            'firmado_en' => now(),
            'ip_firma' => $ip,
            'user_agent_firma' => $userAgent !== null ? substr($userAgent, 0, 1000) : null,
            'snapshot_traspaso' => $snapshot,
            'hash_documento' => $hashDocumento,
            'ruta_pdf' => null,
        ]);

        $this->auditoria->registrar('inventario', 'traspaso-firmado', [
            'tipo_entidad' => AcuseTraspaso::class,
            'entidad_id' => $acuse->getKey(),
            'empresa_id' => $traspaso->empresa_origen_id,
            'descripcion' => 'Acuse de traspaso '.$traspaso->folio.' firmado por '.$firmante->name,
        ]);

        return $acuse;
    }

    /**
     * Efecto POST-commit: materializa el PDF. Nunca debe llamarse dentro de
     * una transacción abierta; nunca lanza (un fallo de PDF no revierte el
     * traspaso ni el acuse ya firmados).
     */
    public function finalizarAcuse(AcuseTraspaso $acuse): AcuseTraspaso
    {
        $this->materializarPdf($acuse);

        return $acuse->refresh();
    }

    public function regenerarPdf(AcuseTraspaso $acuse): AcuseTraspaso
    {
        $this->materializarPdf($acuse);

        return $acuse->refresh();
    }

    private function materializarPdf(AcuseTraspaso $acuse): void
    {
        try {
            $ruta = $this->pdf->generar($acuse);
            $acuse->update(['ruta_pdf' => $ruta]);
        } catch (Throwable $e) {
            Log::error('No se pudo generar el PDF del acuse de traspaso '.$acuse->traspaso->folio, ['excepcion' => $e->getMessage()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function construirSnapshot(TraspasoInventario $traspaso, User $firmante): array
    {
        return [
            'version' => 1,
            'traspaso' => [
                'id' => $traspaso->id,
                'folio' => $traspaso->folio,
                'tipo' => $traspaso->tipo,
                'ocurrido_en' => $traspaso->ocurrido_en->toIso8601String(),
                'motivo' => $traspaso->motivo,
                'notas' => $traspaso->notas,
            ],
            'empresa_origen' => [
                'id' => $traspaso->empresaOrigen->id,
                'codigo' => $traspaso->empresaOrigen->codigo,
                'nombre_comercial' => $traspaso->empresaOrigen->nombre_comercial,
                'razon_social' => $traspaso->empresaOrigen->razon_social,
            ],
            'almacen_origen' => [
                'id' => $traspaso->almacenOrigen->id,
                'codigo' => $traspaso->almacenOrigen->codigo,
                'nombre' => $traspaso->almacenOrigen->nombre,
            ],
            'empresa_destino' => [
                'id' => $traspaso->empresaDestino->id,
                'codigo' => $traspaso->empresaDestino->codigo,
                'nombre_comercial' => $traspaso->empresaDestino->nombre_comercial,
                'razon_social' => $traspaso->empresaDestino->razon_social,
            ],
            'almacen_destino' => [
                'id' => $traspaso->almacenDestino->id,
                'codigo' => $traspaso->almacenDestino->codigo,
                'nombre' => $traspaso->almacenDestino->nombre,
            ],
            'responsable' => [
                'id' => $traspaso->realizadoPor?->id,
                'name' => $traspaso->realizadoPor?->name,
                'email' => $traspaso->realizadoPor?->email,
            ],
            'firmante' => [
                'id' => $firmante->id,
                'name' => $firmante->name,
                'email' => $firmante->email,
            ],
            // Orden determinista por id del renglón.
            'items' => $traspaso->renglones->sortBy('id')->values()->map(fn (TraspasoRenglon $r): array => [
                'control' => $r->control->value,
                'activo_origen' => $r->activo_origen_nombre_snapshot,
                'activo_destino' => $r->activo_destino_nombre_snapshot,
                'talla' => $r->talla_valor_snapshot,
                'cantidad' => (int) $r->cantidad,
                'unidad_codigo' => $r->unidad_codigo_snapshot,
            ])->all(),
            'firmado_en' => now()->toIso8601String(),
        ];
    }
}
