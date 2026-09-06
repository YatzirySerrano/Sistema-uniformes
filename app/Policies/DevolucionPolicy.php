<?php

namespace App\Policies;

use App\Models\Devolucion;
use App\Models\User;

class DevolucionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('devoluciones.ver');
    }

    public function view(User $user, Devolucion $devolucion): bool
    {
        return $user->can('devoluciones.ver') && $user->puedeAccederEmpresa($devolucion->empresa_id);
    }

    public function create(User $user): bool
    {
        return $user->can('devoluciones.crear');
    }

    /**
     * Firma de doble conformidad: el operador con permiso, o el propio
     * colaborador titular (si tiene cuenta) confirmando su devolución.
     */
    public function confirmar(User $user, Devolucion $devolucion): bool
    {
        if (! $user->puedeAccederEmpresa($devolucion->empresa_id)) {
            return false;
        }

        $esTitular = $devolucion->colaborador?->usuario_id === $user->getKey();

        return $user->can('devoluciones.confirmar') || $esTitular;
    }
}
