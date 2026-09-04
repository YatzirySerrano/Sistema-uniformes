<?php

namespace App\Policies;

use App\Models\Conjunto;
use App\Models\User;

class ConjuntoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('conjuntos.ver');
    }

    public function view(User $user, Conjunto $conjunto): bool
    {
        return $user->can('conjuntos.ver') && $user->puedeAccederEmpresa($conjunto->empresa_id);
    }

    public function create(User $user): bool
    {
        return $user->can('conjuntos.crear');
    }

    public function update(User $user, Conjunto $conjunto): bool
    {
        return $user->can('conjuntos.editar') && $user->puedeAccederEmpresa($conjunto->empresa_id);
    }

    public function administrar(User $user, Conjunto $conjunto): bool
    {
        return $user->can('conjuntos.administrar') && $user->puedeAccederEmpresa($conjunto->empresa_id);
    }
}
