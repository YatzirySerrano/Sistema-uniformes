<?php

namespace App\Policies;

use App\Models\Servicio;
use App\Models\User;

/**
 * `Servicio` no tiene `empresa_id` propio (se deriva de `contrato->empresa_id`,
 * ver `Servicio::empresaId()`) — por eso `view`/`update`/`administrar` usan
 * ese helper en vez del patrón directo `$modelo->empresa_id` del resto de
 * Policies simples.
 */
class ServicioPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('servicios.ver');
    }

    public function view(User $user, Servicio $servicio): bool
    {
        return $user->can('servicios.ver') && $user->puedeAccederEmpresa($servicio->empresaId());
    }

    public function create(User $user): bool
    {
        return $user->can('servicios.crear');
    }

    public function update(User $user, Servicio $servicio): bool
    {
        return $user->can('servicios.editar') && $user->puedeAccederEmpresa($servicio->empresaId());
    }

    public function administrar(User $user, Servicio $servicio): bool
    {
        return $user->can('servicios.administrar') && $user->puedeAccederEmpresa($servicio->empresaId());
    }
}
