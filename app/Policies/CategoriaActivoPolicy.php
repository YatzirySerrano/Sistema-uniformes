<?php

namespace App\Policies;

use App\Models\CategoriaActivo;
use App\Models\User;
use App\Soporte\AccesoEmpresa;

/**
 * El catálogo de categorías es compartido a nivel plataforma. Administrarlo
 * exige `categorias-activo.administrar`; para un rol restringido, además, la
 * categoría debe estar habilitada para alguna de sus empresas.
 */
class CategoriaActivoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('activos.ver');
    }

    public function administrar(User $user, ?CategoriaActivo $categoria = null): bool
    {
        if (! $user->can('categorias-activo.administrar')) {
            return false;
        }

        if ($categoria === null || $user->tieneAlcanceGlobal()) {
            return true;
        }

        return $categoria->empresas()
            ->whereIn('empresas.id', app(AccesoEmpresa::class)->idsAutorizados($user))
            ->exists();
    }
}
