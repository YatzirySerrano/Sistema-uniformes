<?php

namespace App\Excepciones;

class AccesoEmpresaNoAutorizadoException extends ExcepcionDeNegocio
{
    public static function crear(): self
    {
        return new self('No tienes acceso a esta empresa.');
    }
}
