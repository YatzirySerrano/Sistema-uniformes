<?php

namespace App\Policies;

use App\Models\TipoActivo;
use App\Models\User;

/**
 * El catálogo de tipos es GLOBAL de plataforma (sin empresa_id ni habilitación
 * por empresa). Administrarlo (alta, edición, activar/desactivar global) exige
 * únicamente el permiso `tipos-activo.administrar`.
 */
class TipoActivoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('activos.ver');
    }

    public function administrar(User $user, ?TipoActivo $tipo = null): bool
    {
        return $user->can('tipos-activo.administrar');
    }
}
