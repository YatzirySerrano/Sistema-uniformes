<?php

namespace App\Policies;

use App\Models\EntregaUniforme;
use App\Models\User;

class EntregaUniformePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('entregas.ver');
    }

    public function view(User $user, EntregaUniforme $entrega): bool
    {
        return $user->can('entregas.ver') && $user->puedeAccederEmpresa($entrega->empresa_id);
    }

    public function create(User $user): bool
    {
        return $user->can('entregas.crear');
    }

    public function firmar(User $user, EntregaUniforme $entrega): bool
    {
        if (! $user->puedeAccederEmpresa($entrega->empresa_id)) {
            return false;
        }

        // El propio colaborador (si tiene cuenta) puede firmar su entrega.
        $esTitular = $entrega->colaborador?->usuario_id === $user->getKey();

        return $user->can('acuses.firmar') || $esTitular;
    }

    public function corregir(User $user, EntregaUniforme $entrega): bool
    {
        return $user->can('entregas.corregir') && $user->puedeAccederEmpresa($entrega->empresa_id);
    }
}
