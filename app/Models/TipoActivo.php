<?php

namespace App\Models;

use App\Models\Concerns\NombreNormalizado;
use Database\Factories\TipoActivoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tipo de activo del **catálogo compartido de plataforma** (Prenda, Equipo de
 * cómputo, Dispositivo móvil, Accesorio, Otro…): la clasificación GENERAL o
 * naturaleza del activo. Ya no pertenece a una empresa: se **habilita por
 * empresa** vía `tipo_activo_empresa`. Opcional (un activo puede no tener tipo).
 *
 * `activo = false` lo retira globalmente de nuevas selecciones; quitar una
 * empresa del pivote sólo lo retira para esa empresa. Sin borrado físico.
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
     * Empresas que tienen habilitado este tipo.
     *
     * @return BelongsToMany<Empresa, $this>
     */
    public function empresas(): BelongsToMany
    {
        return $this->belongsToMany(Empresa::class, 'tipo_activo_empresa')->withTimestamps();
    }

    public function habilitadoPara(int $empresaId): bool
    {
        return $this->empresas()->whereKey($empresaId)->exists();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    /**
     * Tipos habilitados para la empresa indicada.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeParaEmpresa(Builder $query, int $empresaId): Builder
    {
        return $query->whereHas('empresas', fn (Builder $q) => $q->whereKey($empresaId));
    }
}
