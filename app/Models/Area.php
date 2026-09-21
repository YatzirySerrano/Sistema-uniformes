<?php

namespace App\Models;

use App\Models\Concerns\NombreNormalizado;
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
 * `nombre_normalizado` (vía `NombreNormalizado`, mismo mecanismo que los
 * catálogos globales de Activo) impide duplicados que sólo difieren en
 * mayúsculas/espacios DENTRO de una empresa (índice único
 * `empresa_id + nombre_normalizado`); dos empresas distintas sí pueden tener
 * cada una su propia área "Compras" sin chocar.
 *
 * @property int $id
 * @property int $empresa_id
 * @property string $nombre
 * @property string $nombre_normalizado
 * @property string|null $codigo
 * @property string|null $descripcion
 * @property bool $activa
 */
class Area extends Model
{
    /** @use HasFactory<AreaFactory> */
    use HasFactory, NombreNormalizado, PerteneceAEmpresa, SoftDeletes;

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
