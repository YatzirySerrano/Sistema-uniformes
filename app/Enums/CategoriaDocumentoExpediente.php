<?php

namespace App\Enums;

/**
 * Carpeta del expediente digital del colaborador. Es de plataforma (no varía
 * por empresa); cada documento pertenece a una sola categoría.
 */
enum CategoriaDocumentoExpediente: string
{
    case Identificacion = 'identificacion';
    case Contratos = 'contratos';
    case Fiscal = 'fiscal';
    case SeguridadSocial = 'seguridad_social';
    case Comprobantes = 'comprobantes';
    case Constancias = 'constancias';
    case EntregasAcuses = 'entregas_acuses';
    case Otros = 'otros';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Identificacion => 'Identificación',
            self::Contratos => 'Contratos',
            self::Fiscal => 'Fiscal',
            self::SeguridadSocial => 'Seguridad social',
            self::Comprobantes => 'Comprobantes',
            self::Constancias => 'Constancias',
            self::EntregasAcuses => 'Entregas / acuses',
            self::Otros => 'Otros',
        };
    }
}
