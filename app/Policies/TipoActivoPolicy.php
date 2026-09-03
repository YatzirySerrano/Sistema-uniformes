<?php

namespace App\Policies;

use App\Models\TipoActivo;
use App\Models\User;

class TipoActivoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('activos.ver');
    }

    public function administrar(User $user, ?TipoActivo $tipo = null): bool
    {
        if (! $user->can('tipos-activo.administrar')) {
            return false;
        }

        return $tipo === null || $user->puedeAccederEmpresa($tipo->empresa_id);
    }
}
