<?php

namespace App\Policies;

use App\Models\Colaborador;
use App\Models\User;

class ColaboradorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('colaboradores.ver');
    }

    public function view(User $user, Colaborador $colaborador): bool
    {
        return $user->can('colaboradores.ver') && $user->puedeAccederEmpresa($colaborador->empresa_id);
    }

    public function create(User $user): bool
    {
        return $user->can('colaboradores.crear');
    }

    public function update(User $user, Colaborador $colaborador): bool
    {
        return $user->can('colaboradores.editar') && $user->puedeAccederEmpresa($colaborador->empresa_id);
    }

    public function desactivar(User $user, Colaborador $colaborador): bool
    {
        return $user->can('colaboradores.desactivar') && $user->puedeAccederEmpresa($colaborador->empresa_id);
    }

    public function importar(User $user): bool
    {
        return $user->can('colaboradores.importar');
    }
}
