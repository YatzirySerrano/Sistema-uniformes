<?php

namespace App\Enums;

enum EstadoDevolucion: string
{
    case PendienteFirma = 'pendiente_firma';
    case Confirmada = 'confirmada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::PendienteFirma => 'Pendiente de firma',
            self::Confirmada => 'Confirmada',
        };
    }

    public function estaConfirmada(): bool
    {
        return $this === self::Confirmada;
    }
}
