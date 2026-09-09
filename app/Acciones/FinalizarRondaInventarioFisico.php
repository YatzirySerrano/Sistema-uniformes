<?php

namespace App\Acciones;

use App\Enums\EstadoInventarioFisico;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\InventarioFisico;
use App\Servicios\ServicioAuditoria;
use Illuminate\Support\Facades\DB;

/**
 * Cierra una ronda de inventario físico. Transición irreversible: tras
 * finalizar, `EscanearUnidadInventarioFisico` rechaza cualquier escaneo. El
 * `lockForUpdate` sobre la ronda evita que dos cierres simultáneos dupliquen
 * el registro de auditoría o pisen `finalizado_en`.
 */
class FinalizarRondaInventarioFisico
{
    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    public function ejecutar(InventarioFisico $ronda, ?int $usuarioId): InventarioFisico
    {
        return DB::transaction(function () use ($ronda, $usuarioId): InventarioFisico {
            $bloqueada = InventarioFisico::query()->whereKey($ronda->id)->lockForUpdate()->firstOrFail();

            if (! $bloqueada->estaEnProceso()) {
                throw new ExcepcionDeNegocioSimple('Esta ronda ya estaba finalizada.');
            }

            $bloqueada->update([
                'estado' => EstadoInventarioFisico::Finalizado,
                'finalizado_en' => now(),
            ]);

            $this->auditoria->registrar('inventario_fisico', 'ronda_finalizar', [
                'empresa_id' => $bloqueada->empresa_id,
                'tipo_entidad' => InventarioFisico::class,
                'entidad_id' => $bloqueada->id,
                'descripcion' => 'Cierre de ronda de inventario físico «'.$bloqueada->nombre.'» ('.$bloqueada->folio.').',
                'motivo' => $usuarioId !== null ? 'Cerrada por el usuario '.$usuarioId : null,
            ]);

            return $bloqueada;
        });
    }
}
