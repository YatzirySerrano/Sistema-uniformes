<?php

namespace App\Policies;

use App\Models\CambioServicioColaborador;
use App\Models\Colaborador;
use App\Models\EntregaUniforme;
use App\Models\User;

/**
 * Revisión de custodia de un cambio de servicio. Todo se decide por permisos
 * efectivos y alcance, nunca por rol:
 * - Gestionarla (decidir, completar, cancelar) = poder cambiar el servicio
 *   del colaborador (`update` del colaborador).
 * - Devolver lo decidido exige su propio permiso (`devoluciones.crear`, ver
 *   `DevolucionPolicy::create`); redistribuirlo exige `entregas.redistribuir`
 *   + alcance sobre el colaborador. Ninguno se concede implícitamente.
 */
class CambioServicioColaboradorPolicy
{
    public function view(User $user, CambioServicioColaborador $cambio): bool
    {
        $colaborador = $this->colaborador($cambio);

        return $colaborador !== null && ($user->can('view', $colaborador) || $user->can('update', $colaborador));
    }

    public function gestionar(User $user, CambioServicioColaborador $cambio): bool
    {
        $colaborador = $this->colaborador($cambio);

        return $colaborador !== null && $user->can('update', $colaborador);
    }

    /**
     * Redistribuir, en nombre del colaborador revisado, lo decidido como
     * "redistribuir" (la custodia es de él, no del usuario autenticado).
     */
    public function redistribuirCustodia(User $user, CambioServicioColaborador $cambio): bool
    {
        $colaborador = $this->colaborador($cambio);

        return $cambio->estaPendiente()
            && $colaborador !== null
            && $user->can('redistribuir', EntregaUniforme::class)
            && $user->puedeAccederEmpresa($colaborador->empresa_id)
            && $colaborador->sucursal !== null
            && $user->puedeAccederSucursal($colaborador->sucursal);
    }

    private function colaborador(CambioServicioColaborador $cambio): ?Colaborador
    {
        return $cambio->colaborador;
    }
}
