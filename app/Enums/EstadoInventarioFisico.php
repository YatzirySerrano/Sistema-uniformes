<?php

namespace App\Enums;

/**
 * Estado de una ronda de inventario físico. Sólo dos: mientras está
 * `EnProceso` admite escaneos; al pasar a `Finalizado` (irreversible) se
 * congela y ningún escaneo posterior es aceptado.
 */
enum EstadoInventarioFisico: string
{
    case EnProceso = 'en_proceso';
    case Finalizado = 'finalizado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::EnProceso => 'En proceso',
            self::Finalizado => 'Finalizado',
        };
    }
}
