<?php

namespace App\Soporte;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Presentación de timestamps en la zona horaria de la interfaz.
 *
 * Los timestamps se guardan en UTC (`config('app.timezone') === 'UTC'`). La
 * zona de presentación vive en `config('uniformes.zona_horaria')`
 * (`America/Mexico_City` por defecto). Este helper es la ÚNICA forma correcta
 * de imprimir una hora en un PDF o en cualquier salida server-side: nunca
 * `->format()` a secas sobre el Carbon en UTC, y nunca `addHours()/subHours()`.
 *
 * NO aplica a fechas de negocio (`fecha_entrega`, `fecha` de devolución): esas
 * son días sin hora y no se desplazan por zona.
 */
final class FechaHora
{
    public static function zona(): string
    {
        return (string) config('uniformes.zona_horaria', 'America/Mexico_City');
    }

    /**
     * Timestamp → cadena ya convertida a la zona de presentación.
     * `null` → cadena vacía (para blades que iteran datos opcionales).
     */
    public static function local(?DateTimeInterface $momento, string $formato = 'd/m/Y H:i'): string
    {
        if ($momento === null) {
            return '';
        }

        return CarbonImmutable::instance($momento)->setTimezone(self::zona())->format($formato);
    }

    /**
     * Fecha de negocio "de hoy" (`Y-m-d`, sin hora), calculada en la zona de
     * presentación (`America/Mexico_City` por defecto) — NUNCA en UTC
     * (`config('app.timezone')`), que cerca de medianoche puede ya ser "mañana"
     * o todavía "ayer" en la zona real del negocio. Única fuente autoritativa
     * para `fecha_entrega` (Entregas) y `fecha` (Devoluciones) al CREAR un
     * registro nuevo: el valor que mande el cliente en el payload nunca se usa
     * para decidir qué día se guarda.
     */
    public static function hoyNegocio(): string
    {
        return CarbonImmutable::now(self::zona())->toDateString();
    }
}
