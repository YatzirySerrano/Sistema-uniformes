<?php

namespace App\Enums;

/**
 * Salud física de una unidad de seguimiento individual. Eje independiente de
 * `EstadoUnidadActivo` (ciclo de posesión). Sólo `Funcionando` es entregable;
 * `Perdido`/`Robado` son incidencias — no representan una devolución física y
 * se registran con `App\Acciones\MarcarUnidadIncidencia`, nunca volviendo
 * solas a almacén.
 */
enum CondicionUnidadActivo: string
{
    case Funcionando = 'funcionando';
    case EnReparacion = 'en_reparacion';
    case Inservible = 'inservible';
    case Perdido = 'perdido';
    case Robado = 'robado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Funcionando => 'Funcionando',
            self::EnReparacion => 'En reparación',
            self::Inservible => 'Inservible',
            self::Perdido => 'Perdido',
            self::Robado => 'Robado',
        };
    }

    public function esIncidencia(): bool
    {
        return $this === self::Perdido || $this === self::Robado;
    }
}
