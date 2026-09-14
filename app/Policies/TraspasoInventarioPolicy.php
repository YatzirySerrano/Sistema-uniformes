<?php

namespace App\Policies;

use App\Models\TraspasoInventario;
use App\Models\User;

/**
 * Un traspaso involucra DOS empresas. CONSULTARLO (listado, detalle, PDF,
 * firma — ver `AcuseTraspasoPolicy`) exige el permiso `inventario.ver` (el
 * mismo que ya exigía el listado de Movimientos) y acceso a AL MENOS UNA de
 * las dos empresas (origen O destino) — no hace falta acceso a ambas para
 * poder consultarlo, sólo para haberlo creado. CREARLO exige el permiso más
 * fuerte `inventario.transferir`, que además revalida — sin cambios — acceso
 * a AMBAS empresas (`RegistrarTraspasoRequest`).
 */
class TraspasoInventarioPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('inventario.ver');
    }

    public function view(User $user, TraspasoInventario $traspaso): bool
    {
        return $user->can('inventario.ver')
            && ($user->puedeAccederEmpresa($traspaso->empresa_origen_id)
                || $user->puedeAccederEmpresa($traspaso->empresa_destino_id));
    }

    public function create(User $user): bool
    {
        return $user->can('inventario.transferir');
    }
}
