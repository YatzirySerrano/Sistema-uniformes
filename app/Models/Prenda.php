<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use Database\Factories\PrendaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $empresa_id
 * @property string $nombre
 * @property string|null $codigo_interno
 * @property string|null $imagen_ruta
 * @property bool $activa
 */
class Prenda extends Model
{
    /** @use HasFactory<PrendaFactory> */
    use HasFactory, PerteneceAEmpresa, SoftDeletes;

    protected $table = 'prendas';

    protected $fillable = [
        'empresa_id',
        'nombre',
        'descripcion',
        'categoria',
        'codigo_interno',
        'imagen_ruta',
        'activa',
    ];

    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Talla, $this>
     */
    public function tallas(): BelongsToMany
    {
        return $this->belongsToMany(Talla::class, 'prenda_talla')->withTimestamps()->orderBy('tallas.orden');
    }

    /**
     * @return HasMany<SaldoInventario, $this>
     */
    public function saldos(): HasMany
    {
        return $this->hasMany(SaldoInventario::class);
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
