<?php

namespace App\Enums;

enum EstadoEntrega: string
{
    case PendienteFirma = 'pendiente_firma';
    case Firmada = 'firmada';
    case Corregida = 'corregida';
    case Anulada = 'anulada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::PendienteFirma => 'Pendiente de firma',
            self::Firmada => 'Firmada',
            self::Corregida => 'Corregida',
            self::Anulada => 'Anulada',
        };
    }

    public function estaFirmada(): bool
    {
        return $this === self::Firmada || $this === self::Corregida;
    }
}
