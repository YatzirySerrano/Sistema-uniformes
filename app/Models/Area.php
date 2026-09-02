<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use Database\Factories\AreaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Área o departamento organizacional de una empresa. Es la estructura que
 * sustituye al texto libre `colaboradores.area`.
 *
 * @property int $id
 * @property int $empresa_id
 * @property string $nombre
 * @property string|null $codigo
 * @property string|null $descripcion
 * @property bool $activa
 */
class Area extends Model
{
    /** @use HasFactory<AreaFactory> */
    use HasFactory, PerteneceAEmpresa, SoftDeletes;

    protected $table = 'areas';

    protected $fillable = [
        'empresa_id',
        'nombre',
        'codigo',
        'descripcion',
        'activa',
    ];

    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Colaborador, $this>
     */
    public function colaboradores(): HasMany
    {
        return $this->hasMany(Colaborador::class);
    }

    /**
     * Colaboradores activos del área. Replica `Colaborador::scopeActivos` y es la
     * única definición de "colaborador activo" usada en los contadores.
     *
     * @return HasMany<Colaborador, $this>
     */
    public function colaboradoresActivos(): HasMany
    {
        return $this->hasMany(Colaborador::class)->where('activo', true);
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
