<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Empresa;
use App\Soporte\AccesoEmpresa;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Utilidades de contexto de empresa para controladores. El sistema es
 * multiempresa pero NO tiene "empresa activa": la empresa se recibe en cada
 * formulario / filtro y SIEMPRE se valida el acceso (Policy + Form Request);
 * nunca se confía en el `empresa_id` del frontend.
 */
trait ConEmpresa
{
    protected function acceso(): AccesoEmpresa
    {
        return app(AccesoEmpresa::class);
    }

    /**
     * Empresas que el usuario autenticado puede operar.
     *
     * @return Collection<int, Empresa>
     */
    protected function empresasAutorizadas(Request $request): Collection
    {
        return $this->acceso()->empresasAutorizadas($request->user());
    }

    /**
     * @return Collection<int, int>
     */
    protected function idsEmpresasAutorizadas(Request $request): Collection
    {
        return $this->acceso()->idsAutorizados($request->user());
    }

    /**
     * Empresa recibida en un formulario de alta. Se valida el acceso o se
     * responde 403.
     */
    protected function resolverEmpresa(Request $request, string $campo = 'empresa_id'): Empresa
    {
        $empresa = Empresa::query()->findOrFail((int) $request->input($campo));

        abort_unless($request->user()->puedeAccederEmpresa($empresa), 403, 'No tienes acceso a la empresa seleccionada.');

        return $empresa;
    }

    /**
     * Empresa recibida como filtro opcional de un listado. Devuelve null si no
     * se envió o si el usuario no tiene acceso (se ignora silenciosamente y se
     * muestra el listado completo autorizado).
     */
    protected function empresaDelFiltro(Request $request, string $campo = 'empresa_id'): ?Empresa
    {
        $id = (int) $request->input($campo);

        if ($id <= 0) {
            return null;
        }

        $empresa = Empresa::query()->find($id);

        return $empresa !== null && $request->user()->puedeAccederEmpresa($empresa) ? $empresa : null;
    }

    /**
     * Lista compacta de empresas autorizadas para poblar combobox de formulario.
     *
     * @return array<int, array{id: int, codigo: string, nombre_comercial: string}>
     */
    protected function opcionesEmpresas(Request $request): array
    {
        return $this->empresasAutorizadas($request)
            ->map(fn (Empresa $e): array => [
                'id' => $e->id,
                'codigo' => $e->codigo,
                'nombre_comercial' => $e->nombre_comercial,
            ])->values()->all();
    }

    protected function porPagina(): int
    {
        return (int) config('uniformes.por_pagina', 20);
    }
}
