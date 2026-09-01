<?php

namespace App\Policies;

use App\Models\Sucursal;
use App\Models\User;

class SucursalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sucursales.ver');
    }

    public function view(User $user, Sucursal $sucursal): bool
    {
        return $user->can('sucursales.ver') && $user->puedeAccederSucursal($sucursal);
    }

    public function create(User $user): bool
    {
        return $user->can('sucursales.crear');
    }

    public function update(User $user, Sucursal $sucursal): bool
    {
        return $user->can('sucursales.editar') && $user->puedeAccederEmpresa($sucursal->empresa_id);
    }

    public function desactivar(User $user, Sucursal $sucursal): bool
    {
        return $user->can('sucursales.desactivar') && $user->puedeAccederEmpresa($sucursal->empresa_id);
    }
}
