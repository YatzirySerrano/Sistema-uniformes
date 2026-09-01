<?php

namespace App\Soporte;

use App\Excepciones\AccesoEmpresaNoAutorizadoException;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Contexto de empresa activa para la petición en curso. Se registra como
 * singleton y se hidrata en el middleware ResolverEmpresaActiva.
 *
 * Reglas:
 * - empresa() puede ser null. Un contexto vacío NO concede acceso a nada:
 *   las vistas globales se implementan de forma explícita.
 * - La empresa activa siempre se valida contra las empresas autorizadas del
 *   usuario (el Superadministrador puede activar cualquiera).
 */
class ContextoEmpresa
{
    public const SESSION_KEY = 'empresa_activa_id';

    private ?Empresa $empresa = null;

    private ?User $usuario = null;

    private bool $resuelto = false;

    public function paraUsuario(?User $usuario): void
    {
        $this->usuario = $usuario;
        $this->empresa = null;
        $this->resuelto = false;
    }

    public function resolverDesde(?int $empresaId): void
    {
        $this->resuelto = true;

        if ($this->usuario === null) {
            $this->empresa = null;

            return;
        }

        $autorizadas = $this->empresasAutorizadas();

        if ($empresaId !== null) {
            $seleccionada = $autorizadas->firstWhere('id', $empresaId);

            if ($seleccionada instanceof Empresa) {
                $this->empresa = $seleccionada;

                return;
            }
        }

        // Sin selección válida: si sólo administra una empresa, se activa sola.
        $this->empresa = $autorizadas->count() === 1 ? $autorizadas->first() : null;
    }

    public function empresa(): ?Empresa
    {
        return $this->empresa;
    }

    public function empresaObligatoria(): Empresa
    {
        if (! $this->empresa instanceof Empresa) {
            throw new AccesoEmpresaNoAutorizadoException(
                'Selecciona una empresa activa para realizar esta operación.'
            );
        }

        return $this->empresa;
    }

    public function id(): ?int
    {
        return $this->empresa?->getKey();
    }

    public function tieneEmpresa(): bool
    {
        return $this->empresa instanceof Empresa;
    }

    /**
     * Empresas que el usuario puede activar (todas si es Superadministrador).
     *
     * @return Collection<int, Empresa>
     */
    public function empresasAutorizadas(): Collection
    {
        if ($this->usuario === null) {
            return collect();
        }

        if ($this->usuario->esSuperadministrador()) {
            return Empresa::query()->orderBy('nombre_comercial')->get();
        }

        return $this->usuario->empresas()->orderBy('nombre_comercial')->get();
    }

    /**
     * Sucursales de la empresa activa a las que el usuario tiene acceso.
     *
     * @return Collection<int, Sucursal>
     */
    public function sucursalesDisponibles(): Collection
    {
        if (! $this->tieneEmpresa() || $this->usuario === null) {
            return collect();
        }

        $consulta = Sucursal::query()
            ->where('empresa_id', $this->empresa->getKey())
            ->orderBy('nombre');

        if ($this->usuario->esSuperadministrador() || $this->usuario->esAdministrador()) {
            return $consulta->get();
        }

        $asignadas = $this->usuario->sucursales()
            ->where('sucursales.empresa_id', $this->empresa->getKey())
            ->pluck('sucursales.id');

        if ($asignadas->isEmpty()) {
            return $consulta->get();
        }

        return $consulta->whereIn('id', $asignadas)->get();
    }

    public function puedeVerSucursal(int $sucursalId): bool
    {
        return $this->sucursalesDisponibles()->contains('id', $sucursalId);
    }

    public function usuario(): ?User
    {
        return $this->usuario;
    }

    public function estaResuelto(): bool
    {
        return $this->resuelto;
    }
}
