<?php

namespace App\Enums;

/**
 * Forma en que se controla un activo:
 *
 * - Cantidad: se lleva por existencias agregadas (uniformes, accesorios…).
 * - SeguimientoIndividual: cada unidad física se identifica con un código
 *   generado por el sistema (laptops, teléfonos, sillas, herramientas
 *   costosas…). Nunca se pide número de serie / IMEI / etiqueta manual — el
 *   código interno generado es la identidad oficial dentro del sistema. Ver
 *   `App\Models\UnidadActivo`.
 */
enum TipoControlActivo: string
{
    case Cantidad = 'cantidad';
    case SeguimientoIndividual = 'individual';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Cantidad => 'Por cantidad',
            self::SeguimientoIndividual => 'Seguimiento individual',
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
