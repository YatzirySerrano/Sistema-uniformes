<?php

namespace App\Policies;

use App\Models\Activo;
use App\Models\User;

class ActivoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('activos.ver');
    }

    /**
     * Usar el catálogo de activos como SELECTOR dentro de una entrega desde
     * almacén, sin navegar el módulo Activos.
     */
    public function seleccionarEnOperacion(User $user): bool
    {
        return $this->viewAny($user) || $user->can('entregas.crear');
    }

    public function view(User $user, Activo $activo): bool
    {
        return $user->can('activos.ver') && $user->puedeAccederEmpresa($activo->empresa_id);
    }

    public function create(User $user): bool
    {
        return $user->can('activos.crear');
    }

    public function update(User $user, Activo $activo): bool
    {
        return $user->can('activos.editar') && $user->puedeAccederEmpresa($activo->empresa_id);
    }

    public function administrar(User $user, Activo $activo): bool
    {
        return $user->can('activos.administrar') && $user->puedeAccederEmpresa($activo->empresa_id);
    }
}
