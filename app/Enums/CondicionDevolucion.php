<?php

namespace App\Enums;

enum CondicionDevolucion: string
{
    case Reutilizable = 'reutilizable';
    case Danado = 'danado';
    case Baja = 'baja';

    public function reingresaInventario(): bool
    {
        return $this === self::Reutilizable;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Reutilizable => 'Reutilizable',
            self::Danado => 'Dañado',
            self::Baja => 'Baja',
        };
    }
}
