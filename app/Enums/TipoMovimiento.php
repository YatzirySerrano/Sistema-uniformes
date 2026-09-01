<?php

namespace App\Enums;

enum TipoMovimiento: string
{
    case Inicial = 'inicial';
    case Entrada = 'entrada';
    case Entrega = 'entrega';
    case Devolucion = 'devolucion';
    case AjusteEntrada = 'ajuste_entrada';
    case AjusteSalida = 'ajuste_salida';
    case Correccion = 'correccion';
    case TraspasoEntrada = 'traspaso_entrada';
    case TraspasoSalida = 'traspaso_salida';

    public function direccion(): DireccionMovimiento
    {
        return match ($this) {
            self::Inicial, self::Entrada, self::Devolucion, self::AjusteEntrada, self::TraspasoEntrada => DireccionMovimiento::Entrada,
            self::Entrega, self::AjusteSalida, self::TraspasoSalida => DireccionMovimiento::Salida,
            self::Correccion => DireccionMovimiento::Entrada,
        };
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Inicial => 'Carga inicial',
            self::Entrada => 'Entrada',
            self::Entrega => 'Entrega a colaborador',
            self::Devolucion => 'Devolución',
            self::AjusteEntrada => 'Ajuste (entrada)',
            self::AjusteSalida => 'Ajuste (salida)',
            self::Correccion => 'Corrección de entrega',
            self::TraspasoEntrada => 'Traspaso (entrada)',
            self::TraspasoSalida => 'Traspaso (salida)',
        };
    }
}
