<?php

namespace App\Enums;

/**
 * Clase de vehículo del perfil técnico Transporte.
 */
enum ClaseVehiculo: string
{
    case Automovil = 'automovil';
    case Camioneta = 'camioneta';
    case Motocicleta = 'motocicleta';
    case Otro = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Automovil => 'Automóvil',
            self::Camioneta => 'Camioneta',
            self::Motocicleta => 'Motocicleta',
            self::Otro => 'Otro',
        };
    }
}
