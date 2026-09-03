<?php

namespace App\Policies;

use App\Models\CategoriaActivo;
use App\Models\User;

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

        return $categoria === null || $user->puedeAccederEmpresa($categoria->empresa_id);
    }
}
