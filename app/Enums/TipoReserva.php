<?php

namespace App\Enums;

/**
 * Dominio de una `Reserva`: ENTREGA y TRASPASO apartan STOCK de almacén
 * disponible (compiten por la MISMA existencia); DEVOLUCIÓN aparta el
 * DERECHO a devolver una custodia pendiente (nunca "stock"). Ver
 * `App\Models\Reserva`.
 */
enum TipoReserva: string
{
    case Entrega = 'entrega';
    case Devolucion = 'devolucion';
    case Traspaso = 'traspaso';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Entrega => 'Entrega',
            self::Devolucion => 'Devolución',
            self::Traspaso => 'Traspaso',
        };
    }

    /**
     * Tipos cuya demanda se descuenta de la MISMA existencia: una entrega y un
     * traspaso que salen del mismo almacén se ven mutuamente; la devolución
     * aparta custodia, no stock, y nunca se mezcla con ellos.
     *
     * @return list<self>
     */
    public function tiposQueCompartenStock(): array
    {
        return match ($this) {
            self::Entrega, self::Traspaso => [self::Entrega, self::Traspaso],
            self::Devolucion => [self::Devolucion],
        };
    }
}
