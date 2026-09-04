<?php

namespace App\Policies;

use App\Models\UnidadActivo;
use App\Models\User;

class UnidadActivoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('unidades-activo.ver');
    }

    public function view(User $user, UnidadActivo $unidad): bool
    {
        return $user->can('unidades-activo.ver') && $user->puedeAccederEmpresa($unidad->empresa_id);
    }

    public function administrar(User $user, UnidadActivo $unidad): bool
    {
        return $user->can('unidades-activo.administrar') && $user->puedeAccederEmpresa($unidad->empresa_id);
    }
}
