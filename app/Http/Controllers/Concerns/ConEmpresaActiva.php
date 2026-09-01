<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Empresa;
use App\Soporte\ContextoEmpresa;

trait ConEmpresaActiva
{
    protected function contexto(): ContextoEmpresa
    {
        return app(ContextoEmpresa::class);
    }

    protected function empresaActiva(): Empresa
    {
        return $this->contexto()->empresaObligatoria();
    }

    protected function porPagina(): int
    {
        return (int) config('uniformes.por_pagina', 20);
    }
}
