<?php

namespace App\Enums;

enum DireccionMovimiento: string
{
    case Entrada = 'entrada';
    case Salida = 'salida';

    /** Evento registrado en el historial que no altera el stock del almacén. */
    case SinEfecto = 'sin_efecto';

    public function signo(): int
    {
        return match ($this) {
            self::Entrada => 1,
            self::Salida => -1,
            self::SinEfecto => 0,
        };
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Entrada => 'Entrada',
            self::Salida => 'Salida',
            self::SinEfecto => 'Sin efecto en stock',
        };
    }
}
