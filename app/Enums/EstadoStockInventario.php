<?php

namespace App\Enums;

use App\Models\SaldoInventario;

/**
 * Estado de stock de un renglón de inventario (por CANTIDAD, nunca de
 * unidades de seguimiento individual — ver `EstadoVisibleUnidad` para esas).
 * Usado exclusivamente por el módulo de Reportes para presentar la tabla y
 * la gráfica de riesgo de desabasto sin repetir la comparación en cada sitio.
 *
 * Reutiliza `SaldoInventario::estaBajoMinimo()` (`minimo > 0 && cantidad <=
 * minimo`) como única fuente de la regla de negocio — nunca la duplica.
 */
enum EstadoStockInventario: string
{
    case SinExistencias = 'sin_existencias';
    case BajoMinimo = 'bajo_minimo';
    case Correcto = 'correcto';

    public function etiqueta(): string
    {
        return match ($this) {
            self::SinExistencias => 'Sin existencias',
            self::BajoMinimo => 'Bajo mínimo',
            self::Correcto => 'Correcto',
        };
    }

    public static function paraSaldo(SaldoInventario $saldo): self
    {
        return match (true) {
            $saldo->cantidad <= 0 => self::SinExistencias,
            $saldo->estaBajoMinimo() => self::BajoMinimo,
            default => self::Correcto,
        };
    }
}
