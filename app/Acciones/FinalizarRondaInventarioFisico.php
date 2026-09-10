<?php

namespace App\Acciones;

use App\Enums\EstadoInventarioFisico;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\InventarioFisico;
use App\Models\InventarioFisicoFirma;
use App\Models\User;
use App\Servicios\ServicioAuditoria;
use App\Soporte\ValidadorFirma;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cierra una ronda de inventario físico. Transición IRREVERSIBLE: tras
 * finalizar, ni escaneos ni "Presente" ni verificación de cantidades tocan la
 * ronda (ver `EscanearUnidadInventarioFisico`, `MarcarUnidadPresente`,
 * `VerificarExistenciaInventarioFisico`).
 *
 * Exige la firma manuscrita de quien realizó la ronda + su aceptación de
 * responsabilidad (la valida el Form Request `FinalizarRondaRequest`). La firma
 * se decodifica y se escribe en disco ANTES de la transacción (para no retener
 * locks mientras se procesa el base64); pero la fila `InventarioFisicoFirma` y
 * el archivo SÓLO permanecen si el commit tiene éxito.
 *
 * Cierre ATÓMICO (F16-bis): dentro de la transacción, tras `lockForUpdate` de
 * la ronda, se revalida TODO — estado `EnProceso`, sin firma previa, sin
 * renglones de cantidad pendientes. El segundo de dos cierres simultáneos cae
 * aquí y no produce efectos.
 */
class FinalizarRondaInventarioFisico
{
    public const TEXTO_ACEPTACION = 'Declaro que realicé personalmente este inventario físico y que la información aquí registrada (unidades y cantidades verificadas, faltantes y diferencias) es fiel a lo encontrado, bajo mi responsabilidad.';

    public function __construct(
        private readonly ServicioAuditoria $auditoria,
        private readonly ValidadorFirma $validadorFirma,
    ) {}

    public function ejecutar(InventarioFisico $ronda, string $firmaBase64, User $usuario): InventarioFisico
    {
        // Fuera de la transacción: procesa el base64 y escribe el PNG. Si el
        // cierre transaccional falla, se borra (no queda firma huérfana).
        $firma = $this->validadorFirma->validar($firmaBase64);
        $rutaFirma = sprintf('firmas/inventario-fisico/%d/%s.png', $ronda->empresa_id, Str::uuid());
        Storage::disk('local')->put($rutaFirma, $firma['binario']);

        try {
            return DB::transaction(function () use ($ronda, $usuario, $firma, $rutaFirma): InventarioFisico {
                $bloqueada = InventarioFisico::query()->whereKey($ronda->id)->lockForUpdate()->firstOrFail();

                if (! $bloqueada->estaEnProceso()) {
                    throw new ExcepcionDeNegocioSimple('Esta ronda de inventario ya fue finalizada.');
                }

                if ($bloqueada->firma()->exists()) {
                    throw new ExcepcionDeNegocioSimple('Esta ronda de inventario ya fue finalizada.');
                }

                if ($bloqueada->existencias()->whereNull('cantidad_contada')->lockForUpdate()->exists()) {
                    throw new ExcepcionDeNegocioSimple('Faltan artículos por cantidad por verificar antes de cerrar la ronda.');
                }

                $bloqueada->update([
                    'estado' => EstadoInventarioFisico::Finalizado,
                    'finalizado_en' => now(),
                ]);

                InventarioFisicoFirma::query()->create([
                    'inventario_fisico_id' => $bloqueada->id,
                    'ruta_firma' => $rutaFirma,
                    'hash_firma' => hash('sha256', $firma['binario']),
                    'nombre_firmante' => $usuario->name,
                    'texto_aceptado' => self::TEXTO_ACEPTACION,
                    'aceptado_en' => now(),
                    'firmado_por' => $usuario->id,
                ]);

                $this->auditoria->registrar('inventario_fisico', 'ronda_finalizar', [
                    'empresa_id' => $bloqueada->empresa_id,
                    'tipo_entidad' => InventarioFisico::class,
                    'entidad_id' => $bloqueada->id,
                    'descripcion' => 'Cierre de ronda de inventario físico «'.$bloqueada->nombre.'» ('.$bloqueada->folio.').',
                    'motivo' => 'Firmada por '.$usuario->name.'. '.self::TEXTO_ACEPTACION,
                ]);

                return $bloqueada;
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete($rutaFirma);

            throw $e;
        }
    }
}
