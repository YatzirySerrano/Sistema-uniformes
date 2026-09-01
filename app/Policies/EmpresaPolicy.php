<?php

namespace App\Policies;

use App\Models\Empresa;
use App\Models\User;

class EmpresaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('empresas.ver') || $user->can('configuracion-empresa.ver');
    }

    public function view(User $user, Empresa $empresa): bool
    {
        return $user->puedeAccederEmpresa($empresa) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('empresas.crear');
    }

    public function update(User $user, Empresa $empresa): bool
    {
        return $user->puedeAccederEmpresa($empresa)
            && ($user->can('empresas.editar') || $user->can('configuracion-empresa.editar'));
    }

    public function personalizar(User $user, Empresa $empresa): bool
    {
        return $user->puedeAccederEmpresa($empresa) && $user->can('configuracion-empresa.editar');
    }

    public function delete(User $user, Empresa $empresa): bool
    {
        return $user->can('empresas.administrar');
    }
}
