<?php

namespace App\Policies;

use App\Models\User;

/**
 * Personalización visual GLOBAL del sistema: exclusiva de quien tenga
 * `configuracion.*` (Superadministrador y Administrador — ver
 * `App\Soporte\Permisos::porRol()`). No depende de `puedeAccederEmpresa`
 * porque no es un recurso de ninguna empresa.
 */
class ConfiguracionSistemaPolicy
{
    public function ver(User $user): bool
    {
        return $user->can('configuracion.ver') || $user->can('configuracion.administrar');
    }

    public function actualizar(User $user): bool
    {
        return $user->can('configuracion.administrar');
    }
}
