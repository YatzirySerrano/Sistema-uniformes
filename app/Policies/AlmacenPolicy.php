<?php

namespace App\Policies;

use App\Models\Almacen;
use App\Models\User;

class AlmacenPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('almacenes.ver');
    }

    public function view(User $user, Almacen $almacen): bool
    {
        return $user->can('almacenes.ver') && $user->puedeAccederEmpresa($almacen->empresa_id);
    }

    public function create(User $user): bool
    {
        return $user->can('almacenes.crear');
    }

    public function update(User $user, Almacen $almacen): bool
    {
        return $user->can('almacenes.editar') && $user->puedeAccederEmpresa($almacen->empresa_id);
    }

    /**
     * Cambiar el estado y administrar las sucursales abastecidas.
     */
    public function administrar(User $user, Almacen $almacen): bool
    {
        return $user->can('almacenes.administrar') && $user->puedeAccederEmpresa($almacen->empresa_id);
    }
}
