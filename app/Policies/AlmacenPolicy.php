<?php

namespace App\Policies;

use App\Models\Almacen;
use App\Models\User;

class AlmacenPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('almacenes.ver');
    }

    /**
     * Usar almacenes como SELECTOR de origen/destino dentro de una operación
     * (entrega desde almacén, devolución), sin navegar el módulo Almacenes.
     */
    public function seleccionarEnOperacion(User $user): bool
    {
        return $this->viewAny($user)
            || $user->can('entregas.crear')
            || $user->can('devoluciones.crear');
    }

    public function view(User $user, Almacen $almacen): bool
    {
        return $user->can('almacenes.ver') && $this->accedeAAlgunaEmpresa($user, $almacen);
    }

    public function create(User $user): bool
    {
        return $user->can('almacenes.crear');
    }

    public function update(User $user, Almacen $almacen): bool
    {
        return $user->can('almacenes.editar') && $this->accedeAAlgunaEmpresa($user, $almacen);
    }

    /**
     * Cambiar el estado y administrar las empresas abastecidas.
     */
    public function administrar(User $user, Almacen $almacen): bool
    {
        return $user->can('almacenes.administrar') && $this->accedeAAlgunaEmpresa($user, $almacen);
    }

    /**
     * El usuario puede operar el almacén si tiene acceso a al menos una de las
     * empresas que abastece (alcance global siempre lo cumple).
     */
    private function accedeAAlgunaEmpresa(User $user, Almacen $almacen): bool
    {
        if ($user->tieneAlcanceGlobal()) {
            return true;
        }

        return $almacen->empresas()
            ->whereIn('empresas.id', $user->empresas()->select('empresas.id'))
            ->exists();
    }
}
