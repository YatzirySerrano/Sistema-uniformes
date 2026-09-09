<?php

namespace App\Acciones;

use App\Models\Colaborador;
use App\Models\Servicio;
use Illuminate\Support\Facades\DB;

/**
 * Asigna en lote un conjunto de colaboradores a un servicio operativo, en UNA
 * sola petición y UNA sola transacción. NO reimplementa la regla de negocio
 * del cambio de servicio: delega colaborador por colaborador en
 * `CambiarServicioColaborador`, de modo que cada colaborador conserva su traza
 * de auditoría individual (servicio/contrato anterior y nuevo, usuario,
 * fecha). Si cualquier colaborador del lote falla, se revierte TODO el lote —
 * nunca queda una asignación parcialmente aplicada sin informar.
 *
 * @phpstan-type ResumenAsignacion array{total: int, movidos: int, sin_cambio: int}
 */
class AsignarColaboradoresServicio
{
    public function __construct(private readonly CambiarServicioColaborador $cambiarServicio) {}

    /**
     * @param  array<int, int>  $colaboradorIds
     * @return ResumenAsignacion
     */
    public function ejecutar(Servicio $servicio, array $colaboradorIds, ?string $motivo = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $colaboradorIds)));

        return DB::transaction(function () use ($servicio, $ids, $motivo): array {
            $colaboradores = Colaborador::query()->whereIn('id', $ids)->get();

            $movidos = 0;
            $sinCambio = 0;

            foreach ($colaboradores as $colaborador) {
                if ($colaborador->servicio_actual_id === $servicio->id) {
                    $sinCambio++;

                    continue;
                }

                $teniaOtroServicio = $colaborador->servicio_actual_id !== null;

                // Misma lógica central que "Colaborador → Cambiar servicio":
                // valida contrato activo + misma empresa y deja auditoría
                // individual por colaborador.
                $this->cambiarServicio->ejecutar($colaborador, $servicio->id, $motivo);

                if ($teniaOtroServicio) {
                    $movidos++;
                }
            }

            return [
                'total' => $colaboradores->count(),
                'movidos' => $movidos,
                'sin_cambio' => $sinCambio,
            ];
        });
    }
}
