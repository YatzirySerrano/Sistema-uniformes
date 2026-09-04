<?php

namespace App\Models;

use App\Models\Concerns\NombreNormalizado;
use Database\Factories\CategoriaActivoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Categoría de activo del **catálogo compartido de plataforma** (Camisola,
 * Pantalón, Laptop, Teléfono celular…): la clasificación ESPECÍFICA dentro del
 * tipo. Es GLOBAL: visible para todas las empresas por igual, sin habilitación
 * por empresa. Opcional, y puede relacionarse opcionalmente con un tipo de
 * activo (también compartido), sin exigirlo.
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
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('activa', true);
    }
}
