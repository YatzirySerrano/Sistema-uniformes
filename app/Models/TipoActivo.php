<?php

namespace App\Models;

use App\Models\Concerns\NombreNormalizado;
use Database\Factories\TipoActivoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tipo de activo del **catálogo compartido de plataforma** (Prenda, Equipo de
 * cómputo, Dispositivo móvil, Accesorio, Otro…): la clasificación GENERAL o
 * naturaleza del activo. Es GLOBAL: visible para todas las empresas por igual,
 * sin habilitación por empresa. Opcional (un activo puede no tener tipo).
 *
 * `activo = false` lo retira globalmente de nuevas selecciones. Sin borrado
 * físico.
 *
 * @property int $id
 * @property string $nombre
 * @property string $nombre_normalizado
 * @property string|null $codigo
 * @property bool $activo
 */
class TipoActivo extends Model
{
    /** @use HasFactory<TipoActivoFactory> */
    use HasFactory, NombreNormalizado;

    protected $table = 'tipos_activo';

    protected $fillable = [
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
