<?php

namespace App\Models\Concerns;

use App\Soporte\NormalizadorNombre;
use Illuminate\Database\Eloquent\Model;

/**
 * Mantiene una columna `nombre_normalizado` sincronizada con `nombre` para
 * catálogos por empresa (tipos y categorías de activo). El índice único
 * `(empresa_id, nombre_normalizado)` impide duplicados que sólo difieren en
 * mayúsculas o espacios ("Prenda" / " prenda " / "PRENDA").
 *
 * @property string $nombre
 * @property string $nombre_normalizado
 */
trait NombreNormalizado
{
    public static function bootNombreNormalizado(): void
    {
        static::saving(function (Model $modelo): void {
            $modelo->setAttribute(
                'nombre_normalizado',
                NormalizadorNombre::catalogo($modelo->getAttribute('nombre')),
            );
        });
    }

    /**
     * ¿Ya existe en la empresa otro registro cuyo nombre normalizado coincide?
     */
    public static function existeNombreEnEmpresa(int $empresaId, ?string $nombre, ?int $ignorarId = null): bool
    {
        return static::query()
            ->where('empresa_id', $empresaId)
            ->where('nombre_normalizado', NormalizadorNombre::catalogo($nombre))
            ->when($ignorarId !== null, fn ($q) => $q->whereKeyNot($ignorarId))
            ->exists();
    }
}
