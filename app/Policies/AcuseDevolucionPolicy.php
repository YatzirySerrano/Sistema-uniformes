<?php

namespace App\Policies;

use App\Models\AcuseDevolucion;
use App\Models\User;

class AcuseDevolucionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('devoluciones.ver');
    }

    public function view(User $user, AcuseDevolucion $acuse): bool
    {
        if (! $user->puedeAccederEmpresa($acuse->empresa_id)) {
            return false;
        }

        return $user->can('devoluciones.ver') || $this->esTitular($user, $acuse);
    }

    public function verPdf(User $user, AcuseDevolucion $acuse): bool
    {
        if (! $user->puedeAccederEmpresa($acuse->empresa_id)) {
            return false;
        }

        return $user->can('devoluciones.ver-pdf') || $this->esTitular($user, $acuse);
    }

    public function verFirma(User $user, AcuseDevolucion $acuse): bool
    {
        if (! $user->puedeAccederEmpresa($acuse->empresa_id)) {
            return false;
        }

        return $user->can('devoluciones.ver-firma') || $this->esTitular($user, $acuse);
    }

    private function esTitular(User $user, AcuseDevolucion $acuse): bool
    {
        return $acuse->colaborador?->usuario_id === $user->getKey();
    }
}
