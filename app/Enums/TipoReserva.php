<?php

namespace App\Enums;

/**
 * Dominio de una `Reserva`: ENTREGA aparta STOCK de almacén disponible;
 * DEVOLUCIÓN aparta el DERECHO a devolver una custodia pendiente (nunca
 * "stock"). Ver `App\Models\Reserva`.
 */
enum TipoReserva: string
{
    case Entrega = 'entrega';
    case Devolucion = 'devolucion';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Entrega => 'Entrega',
            self::Devolucion => 'Devolución',
        };
    }
}
