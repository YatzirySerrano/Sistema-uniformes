<?php

namespace App\Acciones;

use App\Enums\EstadoDevolucion;
use App\Enums\EstadoUnidadActivo;
use App\Enums\TipoMovimiento;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\AcuseDevolucion;
use App\Models\Devolucion;
use App\Models\UnidadActivo;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioAcuseDevolucionPdf;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioFolios;
use App\Servicios\ServicioInventario;
use App\Servicios\ServicioUnidadesActivo;
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
 * SHA-256 y marca la devolución como confirmada.
 *
 * IMPORTANTE: el inventario NO se toca al registrar la devolución
 * (`RegistrarDevolucion` sólo deja constancia de lo que se devolverá). El
 * reingreso real (saldo por cantidad o `UnidadActivo` individual) se aplica
 * AQUÍ, dentro de la misma transacción protegida que crea el acuse — la
 * devolución sólo se considera concretada cuando ambas firmas, el
 * consentimiento y el movimiento de inventario existen atómicamente.
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
        private readonly ServicioInventario $inventario,
        private readonly ServicioUnidadesActivo $unidadesActivo,
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

                $detalles = $bloqueada->detalles()->get();

                // Revalida que la devolución siga siendo coherente: una unidad
                // pudo haber sido reportada como pérdida/robo (incidencia)
                // mientras la devolución esperaba firma. Se bloquean todas las
                // unidades ANTES de aplicar cualquier movimiento para que, si
                // alguna ya no es válida, no quede ningún reingreso a medias.
                $unidadesBloqueadas = [];
                foreach ($detalles as $detalle) {
                    if ($detalle->unidad_activo_id === null) {
                        continue;
                    }

                    $unidad = UnidadActivo::query()->whereKey($detalle->unidad_activo_id)->lockForUpdate()->first();

                    if (! $unidad instanceof UnidadActivo || $unidad->estado !== EstadoUnidadActivo::Asignada) {
                        throw new ExcepcionDeNegocioSimple('Una de las unidades de esta devolución ya no está asignada (pudo reportarse como pérdida/robo); no se puede confirmar.');
                    }

                    $unidadesBloqueadas[$detalle->getKey()] = $unidad;
                }

                // Reingreso real al inventario: sólo hasta este punto, con
                // ambas firmas y el consentimiento ya validados, el activo
                // vuelve a estar disponible.
                foreach ($detalles as $detalle) {
                    if ($detalle->unidad_activo_id !== null) {
                        $this->unidadesActivo->devolver(
                            $unidadesBloqueadas[$detalle->getKey()],
                            $detalle->condicion_unidad,
                            $bloqueada->almacen_id,
                            $usuarioOperadorId,
                            Devolucion::class,
                            $bloqueada->getKey(),
                            'Devolución '.$bloqueada->folio.' confirmada',
                            $bloqueada->sucursal_id,
                        );

                        continue;
                    }

                    if (! $detalle->reingresa_inventario) {
                        continue;
                    }

                    $this->inventario->registrarMovimiento(new MovimientoInventarioDatos(
                        empresaId: $bloqueada->empresa_id,
                        almacenId: $bloqueada->almacen_id,
                        activoId: $detalle->activo_id,
                        tallaId: $detalle->talla_id,
                        tipo: TipoMovimiento::Devolucion,
                        cantidad: $detalle->cantidad,
                        realizadoPor: $usuarioOperadorId,
                        referenciaTipo: Devolucion::class,
                        referenciaId: $bloqueada->getKey(),
                        motivo: 'Devolución '.$bloqueada->folio.' confirmada',
                        sucursalId: $bloqueada->sucursal_id,
                    ));
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
