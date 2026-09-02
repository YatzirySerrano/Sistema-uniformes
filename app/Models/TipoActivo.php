<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use Database\Factories\TipoActivoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tipo/categoría de activo por empresa (Uniforme / Prenda, Equipo de cómputo,
 * Dispositivo móvil, Accesorio, Otro…). Catálogo extensible; su pantalla de
 * administración llegará en un bloque posterior.
 *
 * @property int $id
 * @property int $empresa_id
 * @property string $nombre
 * @property string|null $codigo
 * @property bool $activo
 */
class TipoActivo extends Model
{
    /** @use HasFactory<TipoActivoFactory> */
    use HasFactory, PerteneceAEmpresa;

    protected $table = 'tipos_activo';

    protected $fillable = [
        'empresa_id',
        'nombre',
        'codigo',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Activo, $this>
     */
    public function activos(): HasMany
    {
        return $this->hasMany(Activo::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }
}
