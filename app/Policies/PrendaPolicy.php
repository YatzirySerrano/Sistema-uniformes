<?php

namespace App\Policies;

use App\Models\Prenda;
use App\Models\User;

class PrendaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('prendas.ver');
    }

    public function view(User $user, Prenda $prenda): bool
    {
        return $user->can('prendas.ver') && $user->puedeAccederEmpresa($prenda->empresa_id);
    }

    public function create(User $user): bool
    {
        return $user->can('prendas.crear');
    }

    public function update(User $user, Prenda $prenda): bool
    {
        return $user->can('prendas.editar') && $user->puedeAccederEmpresa($prenda->empresa_id);
    }
}
