<?php

namespace App\Soporte;

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
    public static function catalogo(?string $nombre): string
    {
        $limpio = preg_replace('/\s+/u', ' ', trim((string) $nombre)) ?? '';

        return mb_strtolower($limpio);
    }
}
