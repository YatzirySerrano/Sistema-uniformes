<?php

namespace App\Soporte;

use App\Models\Almacen;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Resuelve, para un usuario dado, a qué empresas / sucursales / almacenes tiene
 * acceso. Reemplaza a la antigua "empresa activa" en sesión: el contexto de
 * empresa se determina por recurso, formulario o filtro y SIEMPRE se valida el
 * acceso; nunca se confía en el `empresa_id` que llega del frontend.
 *
 * Servicio sin estado: todos los métodos reciben el usuario como parámetro.
 */
class AccesoEmpresa
{
    /**
     * Empresas que el usuario puede operar. Superadministrador y Administrador
     * tienen alcance global (todas las empresas de la plataforma); el resto
     * queda acotado a `empresa_usuario`.
     *
     * @return Collection<int, Empresa>
     */
    public function empresasAutorizadas(User $usuario): Collection
    {
        if ($usuario->tieneAlcanceGlobal()) {
            return Empresa::query()->orderBy('nombre_comercial')->get();
        }

        return $usuario->empresas()->orderBy('nombre_comercial')->get();
    }

    /**
     * @return Collection<int, int>
     */
    public function idsAutorizados(User $usuario): Collection
    {
        return $this->empresasAutorizadas($usuario)->pluck('id');
    }

    public function puedeAcceder(User $usuario, Empresa|int $empresa): bool
    {
        return $usuario->puedeAccederEmpresa($empresa);
    }

    /**
     * Sucursales de una empresa autorizada a las que el usuario tiene acceso.
     * Alcance global: todas. Roles restringidos: las de `sucursal_usuario` en
     * esa empresa, o todas si no tiene ninguna asignada.
     *
     * @return Collection<int, Sucursal>
     */
    public function sucursalesAutorizadas(User $usuario, Empresa|int $empresa): Collection
    {
        $empresaId = $empresa instanceof Empresa ? $empresa->getKey() : $empresa;

        if (! $usuario->puedeAccederEmpresa($empresaId)) {
            return collect();
        }

        $consulta = Sucursal::query()->where('empresa_id', $empresaId)->orderBy('nombre');

        if ($usuario->tieneAlcanceGlobal()) {
            return $consulta->get();
        }

        $asignadas = $usuario->sucursales()
            ->where('sucursales.empresa_id', $empresaId)
            ->pluck('sucursales.id');

        return $asignadas->isEmpty() ? $consulta->get() : $consulta->whereIn('id', $asignadas)->get();
    }

    /**
     * Almacenes activos que abastecen a una empresa autorizada. El almacén ya no
     * se relaciona con sucursales: todos los roles con acceso a la empresa ven
     * sus almacenes activos.
     *
     * @return Collection<int, Almacen>
     */
    public function almacenesAutorizados(User $usuario, Empresa|int $empresa): Collection
    {
        $empresaId = $empresa instanceof Empresa ? $empresa->getKey() : $empresa;

        if (! $usuario->puedeAccederEmpresa($empresaId)) {
            return collect();
        }

        return Almacen::query()
            ->where('activo', true)
            ->paraEmpresa($empresaId)
            ->orderBy('nombre')
            ->get();
    }
}
