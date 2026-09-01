<?php

namespace App\Enums;

enum DireccionMovimiento: string
{
    case Entrada = 'entrada';
    case Salida = 'salida';

    public function signo(): int
    {
        return $this === self::Entrada ? 1 : -1;
    }

    public function etiqueta(): string
    {
        return $this === self::Entrada ? 'Entrada' : 'Salida';
    }
}
