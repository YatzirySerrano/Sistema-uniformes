<?php

namespace App\Policies;

use App\Models\UnidadActivo;
use App\Models\User;

class UnidadActivoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('unidades-activo.ver');
    }

    /**
     * Usar las unidades como SELECTOR dentro de una entrega desde almacén,
     * sin navegar el módulo de unidades.
     */
    public function seleccionarEnOperacion(User $user): bool
    {
        return $this->viewAny($user) || $user->can('entregas.crear');
    }

    public function view(User $user, UnidadActivo $unidad): bool
    {
        return $user->can('unidades-activo.ver') && $user->puedeAccederEmpresa($unidad->empresa_id);
    }

    public function administrar(User $user, UnidadActivo $unidad): bool
    {
        return $user->can('unidades-activo.administrar') && $user->puedeAccederEmpresa($unidad->empresa_id);
    }

    /**
     * Condición física de la unidad (dañada, pérdida / robo, recuperación,
     * restauración). Capacidad INDEPENDIENTE de `administrar` (registrar,
     * corregir datos y dar de baja): un usuario puede reportar el estado
     * físico sin poder dar de alta ni retirar unidades, y viceversa.
     */
    public function gestionarCondicion(User $user, UnidadActivo $unidad): bool
    {
        return $user->can('unidades-activo.condicion') && $user->puedeAccederEmpresa($unidad->empresa_id);
    }
}
