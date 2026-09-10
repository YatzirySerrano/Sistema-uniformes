<?php

namespace App\Policies;

use App\Models\TraspasoInventario;
use App\Models\User;

/**
 * Un traspaso involucra DOS empresas. Verlo o crearlo exige el permiso
 * `inventario.transferir`; ver un traspaso concreto exige además acceso a
 * AMBAS empresas (origen y destino) — nunca basta con una.
 */
class TraspasoInventarioPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('inventario.transferir');
    }

    public function view(User $user, TraspasoInventario $traspaso): bool
    {
        return $user->can('inventario.transferir')
            && $user->puedeAccederEmpresa($traspaso->empresa_origen_id)
            && $user->puedeAccederEmpresa($traspaso->empresa_destino_id);
    }

    public function create(User $user): bool
    {
        return $user->can('inventario.transferir');
    }
}
