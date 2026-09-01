<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('usuarios.ver');
    }

    public function view(User $user, User $objetivo): bool
    {
        return $user->can('usuarios.ver') && $this->compartenEmpresa($user, $objetivo);
    }

    public function create(User $user): bool
    {
        return $user->can('usuarios.crear');
    }

    public function update(User $user, User $objetivo): bool
    {
        return $user->can('usuarios.editar') && $this->compartenEmpresa($user, $objetivo);
    }

    public function desactivar(User $user, User $objetivo): bool
    {
        if ($user->getKey() === $objetivo->getKey()) {
            return false; // Nadie se desactiva a sí mismo.
        }

        if ($objetivo->esSuperadministrador()) {
            return false;
        }

        return $user->can('usuarios.desactivar') && $this->compartenEmpresa($user, $objetivo);
    }

    public function asignarRoles(User $user, User $objetivo): bool
    {
        return $user->can('roles.asignar') && $this->compartenEmpresa($user, $objetivo);
    }

    /**
     * Un administrador sólo gestiona usuarios que pertenecen a alguna de sus
     * empresas autorizadas (o usuarios todavía sin empresa que él está creando).
     */
    private function compartenEmpresa(User $user, User $objetivo): bool
    {
        if ($user->esAdministrador() && $objetivo->esSuperadministrador()) {
            return false;
        }

        $misEmpresas = $user->empresas()->pluck('empresas.id');

        if ($misEmpresas->isEmpty()) {
            return false;
        }

        $suyas = $objetivo->empresas()->pluck('empresas.id');

        return $suyas->isEmpty() || $suyas->intersect($misEmpresas)->isNotEmpty();
    }
}
