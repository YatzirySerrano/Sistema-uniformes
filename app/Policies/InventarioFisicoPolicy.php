<?php

namespace App\Policies;

use App\Models\InventarioFisico;
use App\Models\User;
use App\Soporte\AccesoEmpresa;

/**
 * Toda ronda pertenece a UNA empresa. `puedeAccederEmpresa()` se revalida en
 * cada acción sobre una ronda concreta: una ronda de la Empresa A jamás puede
 * verse ni modificarse por alguien sin acceso a la Empresa A.
 */
class InventarioFisicoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('inventario-fisico.ver');
    }

    public function view(User $user, InventarioFisico $ronda): bool
    {
        return $user->can('inventario-fisico.ver') && $user->puedeAccederEmpresa($ronda->empresa_id);
    }

    public function create(User $user): bool
    {
        return $user->can('inventario-fisico.administrar');
    }

    /**
     * Escanear y finalizar: mismo permiso + acceso a la empresa de la ronda.
     */
    public function administrar(User $user, InventarioFisico $ronda): bool
    {
        return $user->can('inventario-fisico.administrar') && $user->puedeAccederEmpresa($ronda->empresa_id);
    }

    /**
     * Aplicar en bloque las diferencias de la ronda cambia `saldos_inventario`
     * de verdad — poder ADMINISTRAR la ronda (escanear/finalizar) no basta.
     * Exige el mismo permiso que cualquier corrección manual de inventario
     * (`inventario.ajustar`, usado por Existencias globales) + acceso a la
     * empresa Y al almacén concretos de la ronda (un almacén compartido puede
     * abastecer a varias empresas; el usuario debe poder operar ESE almacén
     * para ESA empresa, no sólo tener el permiso en abstracto).
     */
    public function aplicarCorrecciones(User $user, InventarioFisico $ronda): bool
    {
        if (! $user->can('inventario.ajustar') || ! $user->puedeAccederEmpresa($ronda->empresa_id)) {
            return false;
        }

        if ($ronda->almacen_id === null) {
            return false;
        }

        return app(AccesoEmpresa::class)
            ->almacenesAutorizados($user, $ronda->empresa_id)
            ->contains('id', $ronda->almacen_id);
    }
}
