<?php

namespace App\Policies;

use App\Models\Colaborador;
use App\Models\User;

class ColaboradorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('colaboradores.ver');
    }

    public function view(User $user, Colaborador $colaborador): bool
    {
        return $user->can('colaboradores.ver') && $user->puedeAccederEmpresa($colaborador->empresa_id);
    }

    public function create(User $user): bool
    {
        return $user->can('colaboradores.crear');
    }

    public function update(User $user, Colaborador $colaborador): bool
    {
        return $user->can('colaboradores.editar') && $user->puedeAccederEmpresa($colaborador->empresa_id);
    }

    /**
     * Transferir a otra empresa / razón social. Aquí sólo se comprueba el
     * permiso + acceso a la empresa ORIGEN (la del registro); el acceso a la
     * empresa DESTINO lo valida el Form Request y lo revalida la acción.
     */
    public function cambiarEmpresa(User $user, Colaborador $colaborador): bool
    {
        return $user->can('colaboradores.cambiar-empresa') && $user->puedeAccederEmpresa($colaborador->empresa_id);
    }

    public function desactivar(User $user, Colaborador $colaborador): bool
    {
        return $user->can('colaboradores.desactivar') && $user->puedeAccederEmpresa($colaborador->empresa_id);
    }

    public function importar(User $user): bool
    {
        return $user->can('colaboradores.importar');
    }

    public function verExpediente(User $user, Colaborador $colaborador): bool
    {
        return $user->can('colaboradores.expediente-ver') && $user->puedeAccederEmpresa($colaborador->empresa_id);
    }

    public function administrarExpediente(User $user, Colaborador $colaborador): bool
    {
        return $user->can('colaboradores.expediente-administrar') && $user->puedeAccederEmpresa($colaborador->empresa_id);
    }

    public function descargarExpediente(User $user, Colaborador $colaborador): bool
    {
        return $user->can('colaboradores.expediente-descargar') && $user->puedeAccederEmpresa($colaborador->empresa_id);
    }
}
