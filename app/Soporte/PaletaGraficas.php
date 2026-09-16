<?php

namespace App\Soporte;

use App\Enums\CondicionDevolucion;
use App\Enums\EstadoEntrega;
use App\Enums\EstadoVisibleUnidad;
use App\Models\ConfiguracionSistema;

/**
 * Fuente única de colores para las gráficas de Excel (PhpSpreadsheet nativo)
 * y PDF (ApexCharts) de los reportes ejecutivos. Replica, del lado del
 * servidor, la misma identidad visual que ya usa el Dashboard en
 * `resources/js/composables/useGraficasDashboard.ts` — mantener ambos
 * sincronizados si cambia la paleta o los estados semánticos.
 */
final class PaletaGraficas
{
    /**
     * Color de marca (personalización visual global, respeta
     * `ConfiguracionSistema`). Primer color de la paleta categórica.
     */
    public static function principal(): string
    {
        return ConfiguracionSistema::actual()->color_principal;
    }

    /**
     * Paleta categórica genérica para gráficas sin significado semántico
     * (por almacén, por empresa, top N...). Mismo orden que
     * `useGraficasDashboard.ts` (`[primary, chart-2, chart-4, chart-3, chart-5]`
     * — deliberadamente omite chart-1, reservado por su tono cercano a los
     * colores de alerta). Cicla si hay más de 5 categorías.
     */
    public static function serie(int $indice): string
    {
        $paleta = [
            self::principal(),
            self::hslAHex(173, 58, 39), // --chart-2
            self::hslAHex(43, 74, 66),  // --chart-4
            self::hslAHex(197, 37, 24), // --chart-3
            self::hslAHex(27, 87, 67),  // --chart-5
        ];

        return $paleta[$indice % count($paleta)];
    }

    /**
     * Mismo mapeo hex que `COLORES_ESTADO_UNIDAD` en
     * `useGraficasDashboard.ts` — NO recalcular a ojo, mantener sincronizado.
     */
    public static function estadoUnidad(EstadoVisibleUnidad $estado): string
    {
        return match ($estado) {
            EstadoVisibleUnidad::Disponible => '#10b981',
            EstadoVisibleUnidad::Asignado => '#3b82f6',
            EstadoVisibleUnidad::Reparacion => '#f59e0b',
            EstadoVisibleUnidad::Perdido => '#f87171',
            EstadoVisibleUnidad::Robado => '#dc2626',
            EstadoVisibleUnidad::Baja => '#9ca3af',
        };
    }

    public static function condicionDevolucion(CondicionDevolucion $condicion): string
    {
        return match ($condicion) {
            CondicionDevolucion::Reutilizable => '#10b981',
            CondicionDevolucion::Danado => '#f59e0b',
            CondicionDevolucion::Baja => '#9ca3af',
        };
    }

    public static function estadoEntrega(EstadoEntrega $estado): string
    {
        return match ($estado) {
            EstadoEntrega::Firmada => '#10b981',
            EstadoEntrega::PendienteFirma => '#f59e0b',
            EstadoEntrega::Corregida => '#3b82f6',
            EstadoEntrega::Anulada => '#9ca3af',
        };
    }

    /**
     * Verde/gris — activo-inactivo, bajo mínimo/normal y cualquier otro
     * booleano de negocio con la misma semántica (positivo = verde).
     */
    public static function booleano(bool $positivo): string
    {
        return $positivo ? '#10b981' : '#9ca3af';
    }

    /**
     * Advertencia — riesgo de desabasto y alertas de negocio genéricas que no
     * son un estado semántico ya cubierto arriba.
     */
    public static function alerta(): string
    {
        return '#f59e0b';
    }

    /**
     * Conversión HSL → hex exacta (grados 0-360, porcentajes 0-100), para no
     * transcribir a ojo los `--chart-N` definidos en `resources/css/app.css`.
     */
    private static function hslAHex(int $h, int $s, int $l): string
    {
        $s /= 100;
        $l /= 100;

        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;

        [$r, $g, $b] = match (true) {
            $h < 60 => [$c, $x, 0],
            $h < 120 => [$x, $c, 0],
            $h < 180 => [0, $c, $x],
            $h < 240 => [0, $x, $c],
            $h < 300 => [$x, 0, $c],
            default => [$c, 0, $x],
        };

        $aByte = fn (float $v): string => str_pad(dechex((int) round(($v + $m) * 255)), 2, '0', STR_PAD_LEFT);

        return '#'.$aByte($r).$aByte($g).$aByte($b);
    }
}
