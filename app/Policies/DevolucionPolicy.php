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
}
