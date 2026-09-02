<?php

namespace App\Policies;

use App\Models\Area;
use App\Models\User;

class AreaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('areas.ver');
    }

    public function view(User $user, Area $area): bool
    {
        return $user->can('areas.ver') && $user->puedeAccederEmpresa($area->empresa_id);
    }

    public function create(User $user): bool
    {
        return $user->can('areas.crear');
    }

    public function update(User $user, Area $area): bool
    {
        return $user->can('areas.editar') && $user->puedeAccederEmpresa($area->empresa_id);
    }

    public function desactivar(User $user, Area $area): bool
    {
        return $user->can('areas.desactivar') && $user->puedeAccederEmpresa($area->empresa_id);
    }
}
