<?php

namespace App\Policies;

use App\Models\AcuseTraspaso;
use App\Models\User;

/**
 * El acuse de un traspaso hereda el mismo alcance que ver el traspaso mismo
 * (`TraspasoInventarioPolicy::view`): un traspaso involucra DOS empresas, así
 * que ver su firma/PDF exige acceso a AMBAS — nunca basta con una sola.
 */
class AcuseTraspasoPolicy
{
    public function verPdf(User $user, AcuseTraspaso $acuse): bool
    {
        return $user->can('inventario.transferir')
            && $user->puedeAccederEmpresa($acuse->empresa_origen_id)
            && $user->puedeAccederEmpresa($acuse->empresa_destino_id);
    }

    public function verFirma(User $user, AcuseTraspaso $acuse): bool
    {
        return $this->verPdf($user, $acuse);
    }
}
