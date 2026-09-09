<?php

namespace App\Policies;

use App\Models\InventarioFisico;
use App\Models\User;

/**
 * Toda ronda pertenece a UNA empresa. `puedeAccederEmpresa()` se revalida en
 * cada acción sobre una ronda concreta: una ronda de la Empresa A jamás puede
 * verse ni modificarse por alguien sin acceso a la Empresa A.
 */
class InventarioFisicoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('inventario-fisico.ver');
    }

    public function view(User $user, InventarioFisico $ronda): bool
    {
        return $user->can('inventario-fisico.ver') && $user->puedeAccederEmpresa($ronda->empresa_id);
    }

    public function create(User $user): bool
    {
        return $user->can('inventario-fisico.administrar');
    }

    /**
     * Escanear y finalizar: mismo permiso + acceso a la empresa de la ronda.
     */
    public function administrar(User $user, InventarioFisico $ronda): bool
    {
        return $user->can('inventario-fisico.administrar') && $user->puedeAccederEmpresa($ronda->empresa_id);
    }
}
