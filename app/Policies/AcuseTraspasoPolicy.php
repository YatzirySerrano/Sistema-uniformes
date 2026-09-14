<?php

namespace App\Policies;

use App\Models\AcuseTraspaso;
use App\Models\User;

/**
 * El acuse de un traspaso hereda EXACTAMENTE el mismo alcance que consultar
 * el traspaso mismo (`TraspasoInventarioPolicy::view`): permiso
 * `inventario.ver` + acceso a AL MENOS UNA de las dos empresas (origen o
 * destino). Ver el PDF y ver la firma son la MISMA regla a propósito — son
 * parte del mismo comprobante, nunca debe poder verse uno sin el otro.
 */
class AcuseTraspasoPolicy
{
    public function verPdf(User $user, AcuseTraspaso $acuse): bool
    {
        return $user->can('inventario.ver')
            && ($user->puedeAccederEmpresa($acuse->empresa_origen_id)
                || $user->puedeAccederEmpresa($acuse->empresa_destino_id));
    }

    public function verFirma(User $user, AcuseTraspaso $acuse): bool
    {
        return $this->verPdf($user, $acuse);
    }
}
