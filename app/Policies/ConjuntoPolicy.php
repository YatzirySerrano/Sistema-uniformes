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

    /**
     * Usar conjuntos como SELECTOR dentro de una entrega desde almacén (y
     * consultar su disponibilidad), sin navegar el módulo Conjuntos.
     */
    public function seleccionarEnOperacion(User $user): bool
    {
        return $this->viewAny($user) || $user->can('entregas.crear');
    }

    public function view(User $user, Conjunto $conjunto): bool
    {
        return $user->can('conjuntos.ver') && $user->puedeAccederEmpresa($conjunto->empresa_id);
    }

    public function consultarDisponibilidad(User $user, Conjunto $conjunto): bool
    {
        return $this->seleccionarEnOperacion($user) && $user->puedeAccederEmpresa($conjunto->empresa_id);
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
