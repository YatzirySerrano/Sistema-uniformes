<?php

namespace App\Models;

use App\Models\Concerns\NombreNormalizado;
use Database\Factories\CategoriaActivoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Categoría de activo del **catálogo compartido de plataforma** (Camisola,
 * Pantalón, Laptop, Teléfono celular…): la clasificación ESPECÍFICA dentro del
 * tipo. Ya no pertenece a una empresa: se **habilita por empresa** vía
 * `categoria_activo_empresa`. Opcional, y puede relacionarse opcionalmente con
 * un tipo de activo (también compartido), sin exigirlo.
 *
 * @property int $id
 * @property int|null $tipo_activo_id
 * @property string $nombre
 * @property string $nombre_normalizado
 * @property string|null $codigo
 * @property bool $activa
 */
class CategoriaActivo extends Model
{
    /** @use HasFactory<CategoriaActivoFactory> */
    use HasFactory, NombreNormalizado;

    protected $table = 'categorias_activo';

    protected $fillable = [
        'tipo_activo_id',
        'nombre',
        'codigo',
        'activa',
    ];

    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<TipoActivo, $this>
     */
    public function tipoActivo(): BelongsTo
    {
        return $this->belongsTo(TipoActivo::class);
    }

    /**
     * @return HasMany<Activo, $this>
     */
    public function activos(): HasMany
    {
        return $this->hasMany(Activo::class, 'categoria_id');
    }

    /**
     * Empresas que tienen habilitada esta categoría.
     *
     * @return BelongsToMany<Empresa, $this>
     */
    public function empresas(): BelongsToMany
    {
        return $this->belongsToMany(Empresa::class, 'categoria_activo_empresa', 'categoria_activo_id', 'empresa_id')->withTimestamps();
    }

    public function habilitadaPara(int $empresaId): bool
    {
        return $this->empresas()->whereKey($empresaId)->exists();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('activa', true);
    }

    /**
     * Categorías habilitadas para la empresa indicada.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeParaEmpresa(Builder $query, int $empresaId): Builder
    {
        return $query->whereHas('empresas', fn (Builder $q) => $q->whereKey($empresaId));
    }
}
