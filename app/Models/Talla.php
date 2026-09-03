<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use Database\Factories\TallaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Variante / talla de una empresa. Puede ser tradicional (S, M, 32, 36R…) o
 * "sin variante" (`es_comodin = true`): una fila por empresa que representa a los
 * activos por cantidad que no usan tallas. La comodín NO se muestra en la
 * administración ni en el selector del formulario de activo.
 *
 * @property int $id
 * @property int $empresa_id
 * @property string $valor
 * @property int $orden
 * @property bool $activa
 * @property bool $es_comodin
 *
 * Compatibilidad: la relación con el catálogo se llama ahora `activos()`
 * (antes `prendas()`), sobre el pivote `activo_talla`.
 */
class Talla extends Model
{
    /** @use HasFactory<TallaFactory> */
    use HasFactory, PerteneceAEmpresa;

    protected $table = 'tallas';

    protected $fillable = [
        'empresa_id',
        'valor',
        'orden',
        'activa',
        'es_comodin',
    ];

    protected function casts(): array
    {
        return [
            'orden' => 'integer',
            'activa' => 'boolean',
            'es_comodin' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Activo, $this>
     */
    public function activos(): BelongsToMany
    {
        return $this->belongsToMany(Activo::class, 'activo_talla')->withTimestamps();
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
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOrdenadas(Builder $query): Builder
    {
        return $query->orderBy('orden')->orderBy('valor');
    }

    /**
     * Variantes que el usuario administra y elige (excluye la comodín "sin
     * variante", que se resuelve automáticamente en el backend).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSeleccionables(Builder $query): Builder
    {
        return $query->where('es_comodin', false);
    }
}
