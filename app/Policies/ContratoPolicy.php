<?php

namespace App\Policies;

use App\Models\Contrato;
use App\Models\User;

class ContratoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('contratos.ver');
    }

    public function view(User $user, Contrato $contrato): bool
    {
        return $user->can('contratos.ver') && $user->puedeAccederEmpresa($contrato->empresa_id);
    }

    public function create(User $user): bool
    {
        return $user->can('contratos.crear');
    }

    public function update(User $user, Contrato $contrato): bool
    {
        return $user->can('contratos.editar') && $user->puedeAccederEmpresa($contrato->empresa_id);
    }

    public function administrar(User $user, Contrato $contrato): bool
    {
        return $user->can('contratos.administrar') && $user->puedeAccederEmpresa($contrato->empresa_id);
    }
}
