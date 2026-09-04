<?php

namespace App\Models\Concerns;

use App\Soporte\NormalizadorNombre;
use Illuminate\Database\Eloquent\Model;

/**
 * Mantiene una columna normalizada sincronizada con el nombre visible de un
 * catálogo (tipos, categorías y variantes de activo). El índice único sobre la
 * columna normalizada impide duplicados que sólo difieren en mayúsculas o
 * espacios ("Prenda" / " prenda " / "PRENDA"; "M" / " m ").
 *
 * Por defecto normaliza `nombre` → `nombre_normalizado`. Un modelo con otra
 * columna (p. ej. `Talla` usa `valor`) sobreescribe `columnaNombre()` /
 * `columnaNombreNormalizado()`.
 *
 * Desde el Bloque de catálogos compartidos la unicidad es **a nivel plataforma**
 * (los catálogos ya no pertenecen a una empresa): `existeNombre()` no recibe
 * `empresa_id`.
 */
trait NombreNormalizado
{
    public static function columnaNombre(): string
    {
        return 'nombre';
    }

    public static function columnaNombreNormalizado(): string
    {
        return 'nombre_normalizado';
    }

    public static function bootNombreNormalizado(): void
    {
        $origen = static::columnaNombre();
        $destino = static::columnaNombreNormalizado();

        static::saving(function (Model $modelo) use ($origen, $destino): void {
            $modelo->setAttribute(
                $destino,
                NormalizadorNombre::catalogo($modelo->getAttribute($origen)),
            );
        });
    }

    /**
     * ¿Ya existe otro registro del catálogo cuyo nombre normalizado coincide?
     */
    public static function existeNombre(?string $nombre, ?int $ignorarId = null): bool
    {
        return static::query()
            ->where(static::columnaNombreNormalizado(), NormalizadorNombre::catalogo($nombre))
            ->when($ignorarId !== null, fn ($q) => $q->whereKeyNot($ignorarId))
            ->exists();
    }
}
