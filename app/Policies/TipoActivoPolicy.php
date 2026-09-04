<?php

namespace App\Policies;

use App\Models\TipoActivo;
use App\Models\User;
use App\Soporte\AccesoEmpresa;

/**
 * El catálogo de tipos es compartido a nivel plataforma. Administrarlo (alta,
 * activar/desactivar global, habilitar por empresa) exige el permiso
 * `tipos-activo.administrar`; para un rol restringido, además, el tipo debe
 * estar habilitado para alguna de sus empresas.
 */
class TipoActivoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('activos.ver');
    }

    public function administrar(User $user, ?TipoActivo $tipo = null): bool
    {
        if (! $user->can('tipos-activo.administrar')) {
            return false;
        }

        if ($tipo === null || $user->tieneAlcanceGlobal()) {
            return true;
        }

        return $tipo->empresas()
            ->whereIn('empresas.id', app(AccesoEmpresa::class)->idsAutorizados($user))
            ->exists();
    }
}
