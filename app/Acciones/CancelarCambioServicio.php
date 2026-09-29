<?php

namespace App\Acciones;

use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\CambioServicioColaborador;
use App\Models\Colaborador;
use App\Servicios\ServicioAuditoria;
use Illuminate\Support\Facades\DB;

/**
 * Cancela una revisión de cambio de servicio pendiente: el colaborador
 * conserva su servicio. Las devoluciones o redistribuciones que ya se
 * firmaron durante la revisión NO se deshacen (son operaciones reales,
 * independientes y con su propio acuse).
 */
class CancelarCambioServicio
{
    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    public function ejecutar(CambioServicioColaborador $cambio, int $usuarioId): CambioServicioColaborador
    {
        return DB::transaction(function () use ($cambio, $usuarioId): CambioServicioColaborador {
            /** @var CambioServicioColaborador $cambio */
            $cambio = CambioServicioColaborador::query()->whereKey($cambio->getKey())->lockForUpdate()->firstOrFail();

            if (! $cambio->estaPendiente()) {
                throw new ExcepcionDeNegocioSimple('Esta revisión de cambio de servicio ya fue completada o cancelada.');
            }

            $cambio->update([
                'estado' => CambioServicioColaborador::CANCELADO,
                'cancelado_por' => $usuarioId,
                'cancelado_en' => now(),
            ]);

            $nombre = Colaborador::query()->whereKey($cambio->colaborador_id)->value('nombre_completo');

            $this->auditoria->registrar('colaboradores', 'cancelar_cambio_servicio', [
                'tipo_entidad' => CambioServicioColaborador::class,
                'entidad_id' => $cambio->getKey(),
                'empresa_id' => $cambio->empresa_id,
                'descripcion' => "Revisión de cambio de servicio cancelada para {$nombre}; conserva su servicio actual.",
            ]);

            return $cambio;
        });
    }
}
