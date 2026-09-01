<?php

namespace App\Excepciones;

class EntregaYaFirmadaException extends ExcepcionDeNegocio
{
    public static function crear(): self
    {
        return new self('Esta entrega ya fue firmada y no puede modificarse directamente. Registra una corrección administrativa.');
    }

    public static function yaTieneAcuse(): self
    {
        return new self('Esta entrega ya cuenta con un acuse de recepción firmado.');
    }
}
