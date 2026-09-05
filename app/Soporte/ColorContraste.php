<?php

namespace App\Soporte;

/**
 * Utilidades de color para validar legibilidad (aproximación WCAG) entre un
 * fondo y su texto, usadas por la personalización visual global.
 */
final class ColorContraste
{
    /**
     * Devuelve #ffffff o #0f172a según la luminancia del fondo, para
     * proponer un color de texto legible por defecto.
     */
    public static function contrastante(string $hex): string
    {
        return self::luminancia($hex) > 0.55 ? '#0f172a' : '#ffffff';
    }

    /**
     * Razón de contraste WCAG entre dos colores (1 a 21). >= 4.5 es el
     * mínimo recomendado para texto normal, >= 3 para texto/iconos grandes
     * como el texto de un botón.
     */
    public static function razonContraste(string $hexA, string $hexB): float
    {
        $lA = self::luminanciaRelativa($hexA);
        $lB = self::luminanciaRelativa($hexB);

        $claro = max($lA, $lB);
        $oscuro = min($lA, $lB);

        return ($claro + 0.05) / ($oscuro + 0.05);
    }

    private static function luminancia(string $hex): float
    {
        [$r, $g, $b] = self::rgb($hex);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    private static function luminanciaRelativa(string $hex): float
    {
        [$r, $g, $b] = self::rgb($hex);

        $canal = fn (float $c): float => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;

        return 0.2126 * $canal($r) + 0.7152 * $canal($g) + 0.0722 * $canal($b);
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            return [0.0, 0.0, 0.0];
        }

        return [
            hexdec(substr($hex, 0, 2)) / 255,
            hexdec(substr($hex, 2, 2)) / 255,
            hexdec(substr($hex, 4, 2)) / 255,
        ];
    }
}
