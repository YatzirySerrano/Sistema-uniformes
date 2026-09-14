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

    /**
     * Histórico laboral completo (todas las empresas por las que pasó el
     * colaborador). Mismo criterio que el desglose agregado que ya existe en
     * `show()`: sólo alcance global — un Supervisor/Encargado restringido
     * nunca debe reconstruir la historia de una empresa que no puede
     * consultar, ni siquiera de la empresa actual del colaborador.
     */
    public function verHistorico(User $user, Colaborador $colaborador): bool
    {
        return $user->can('colaboradores.ver') && $user->tieneAlcanceGlobal();
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
