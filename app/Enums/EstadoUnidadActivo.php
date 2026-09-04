<?php

namespace App\Enums;

/**
 * Ciclo de POSESIÓN de una unidad de seguimiento individual. Eje
 * independiente de `CondicionUnidadActivo` (salud física): una unidad puede
 * estar `EnAlmacen` y `Inservible` a la vez (no es entregable, pero no está
 * asignada a nadie ni dada de baja).
 */
enum EstadoUnidadActivo: string
{
    case EnAlmacen = 'en_almacen';
    case Asignada = 'asignada';
    case Baja = 'baja';

    public function etiqueta(): string
    {
        return match ($this) {
            self::EnAlmacen => 'En almacén',
            self::Asignada => 'Asignada',
            self::Baja => 'Baja',
        };
    }
}
