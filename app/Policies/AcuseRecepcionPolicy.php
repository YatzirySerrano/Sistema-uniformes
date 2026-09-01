<?php

namespace App\Policies;

use App\Models\AcuseRecepcion;
use App\Models\User;

class AcuseRecepcionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('acuses.ver');
    }

    public function view(User $user, AcuseRecepcion $acuse): bool
    {
        if (! $user->puedeAccederEmpresa($acuse->empresa_id)) {
            return false;
        }

        return $user->can('acuses.ver') || $this->esTitular($user, $acuse);
    }

    public function verPdf(User $user, AcuseRecepcion $acuse): bool
    {
        if (! $user->puedeAccederEmpresa($acuse->empresa_id)) {
            return false;
        }

        return $user->can('acuses.ver-pdf') || $this->esTitular($user, $acuse);
    }

    public function verFirma(User $user, AcuseRecepcion $acuse): bool
    {
        if (! $user->puedeAccederEmpresa($acuse->empresa_id)) {
            return false;
        }

        return $user->can('acuses.ver-firma') || $this->esTitular($user, $acuse);
    }

    private function esTitular(User $user, AcuseRecepcion $acuse): bool
    {
        return $acuse->colaborador?->usuario_id === $user->getKey();
    }
}
