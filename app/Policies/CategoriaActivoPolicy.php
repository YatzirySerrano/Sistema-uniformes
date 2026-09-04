<?php

namespace App\Policies;

use App\Models\CategoriaActivo;
use App\Models\User;

/**
 * El catálogo de categorías es GLOBAL de plataforma (sin empresa_id ni
 * habilitación por empresa). Administrarlo exige únicamente el permiso
 * `categorias-activo.administrar`.
 */
class CategoriaActivoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('activos.ver');
    }

    public function administrar(User $user, ?CategoriaActivo $categoria = null): bool
    {
        return $user->can('categorias-activo.administrar');
    }
}
