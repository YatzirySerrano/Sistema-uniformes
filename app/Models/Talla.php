<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use Database\Factories\TallaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property int $empresa_id
 * @property string $valor
 * @property int $orden
 * @property bool $activa
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
    ];

    protected function casts(): array
    {
        return [
            'orden' => 'integer',
            'activa' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Prenda, $this>
     */
    public function prendas(): BelongsToMany
    {
        return $this->belongsToMany(Prenda::class, 'prenda_talla')->withTimestamps();
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
}
