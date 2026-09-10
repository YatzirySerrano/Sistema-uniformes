<?php

namespace App\Soporte;

use Illuminate\Support\Str;

/**
 * Normaliza nombres de catálogo (tipos y categorías de activo) para comparar
 * unicidad sin distinguir mayúsculas ni espacios sobrantes: "Prenda",
 * " prenda " y "PRENDA" se consideran el mismo nombre dentro de una empresa.
 *
 * Se usa en el hook `saving` de los modelos (columna `nombre_normalizado`), en
 * los Form Requests y en la migración de backfill, para que haya una sola
 * definición de "mismo nombre".
 */
final class NormalizadorNombre
{
    /** Slug del código visible de unidad recortado para caber en varchar(40) con "-000001". */
    private const MAX_SLUG_CODIGO = 30;

    public static function catalogo(?string $nombre): string
    {
        $limpio = preg_replace('/\s+/u', ' ', trim((string) $nombre)) ?? '';

        return mb_strtolower($limpio);
    }

    /**
     * Prefijo del código VISIBLE de una unidad identificada, derivado sólo del
     * nombre del Activo: mayúsculas, sin acentos, espacios → guiones, caracteres
     * seguros. Ej. "Cámara de seguridad" → "CAMARA-DE-SEGURIDAD". Independiente
     * de Empresa/Sucursal/Almacén. Recortado a 30 caracteres (cabe en el
     * `varchar(40)` de `unidades_activo.codigo` junto con "-000001"). Fallback
     * "UNIDAD" si el nombre no produce un slug utilizable.
     */
    public static function codigoActivo(?string $nombre): string
    {
        $slug = Str::upper(Str::slug((string) $nombre, '-'));
        $slug = rtrim(mb_substr($slug, 0, self::MAX_SLUG_CODIGO), '-');

        return $slug !== '' ? $slug : 'UNIDAD';
    }
}
