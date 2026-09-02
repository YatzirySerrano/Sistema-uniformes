<?php

namespace App\Enums;

/**
 * Forma en que se controla un activo:
 *
 * - Cantidad: se lleva por existencias agregadas (uniformes, accesorios…).
 * - Serializado: cada unidad se identifica individualmente por número de serie /
 *   IMEI (laptops, teléfonos, tablets…). El flujo de unidades serializadas se
 *   implementará en un bloque posterior; aquí sólo se contempla en el catálogo.
 */
enum TipoControlActivo: string
{
    case Cantidad = 'cantidad';
    case Serializado = 'serializado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Cantidad => 'Por cantidad',
            self::Serializado => 'Serializado',
        };
    }

    /**
     * @return array<int, array{valor: string, etiqueta: string}>
     */
    public static function opciones(): array
    {
        return array_map(
            fn (self $caso): array => ['valor' => $caso->value, 'etiqueta' => $caso->etiqueta()],
            self::cases(),
        );
    }
}
