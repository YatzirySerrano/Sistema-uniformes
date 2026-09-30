<?php

namespace App\Enums;

/**
 * Para QUÉ recibe un colaborador un bien (por renglón de entrega). No es otro
 * inventario: la custodia sigue siendo una sola; esto sólo registra la
 * intención de la asignación.
 *
 * - `UsoPersonal`: lo usa él (su laptop, su uniforme, su vehículo…).
 * - `Redistribucion`: lo resguarda para entregarlo a otros.
 *
 * Un renglón SIN finalidad (`null`: entregas anteriores a este dato, o una
 * API que no la indicó) se trata como "sin clasificar": sigue contando en la
 * custodia pero, por seguridad, NO se ofrece para redistribuir salvo con el
 * mismo permiso que los bienes personales, hasta que alguien lo clasifique.
 */
enum FinalidadCustodia: string
{
    case UsoPersonal = 'uso_personal';
    case Redistribucion = 'redistribucion';

    public function etiqueta(): string
    {
        return match ($this) {
            self::UsoPersonal => 'Uso personal',
            self::Redistribucion => 'Para redistribuir',
        };
    }

    public static function etiquetaDe(?self $finalidad): string
    {
        return $finalidad?->etiqueta() ?? 'Sin clasificar';
    }

    /**
     * @return list<array{valor: string, etiqueta: string}>
     */
    public static function opciones(): array
    {
        return array_map(fn (self $f): array => ['valor' => $f->value, 'etiqueta' => $f->etiqueta()], self::cases());
    }
}
