<?php

namespace App\Models;

use Database\Factories\EmpresaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $codigo
 * @property string $nombre_comercial
 * @property string|null $razon_social
 * @property string|null $logo_ruta
 * @property string $color_principal
 * @property string $color_secundario
 * @property string $color_acento
 * @property bool $activa
 */
class Empresa extends Model
{
    /** @use HasFactory<EmpresaFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'empresas';

    protected $fillable = [
        'codigo',
        'nombre_comercial',
        'razon_social',
        'rfc',
        'logo_ruta',
        'telefono',
        'correo',
        'direccion',
        'color_principal',
        'color_secundario',
        'color_acento',
        'activa',
    ];

    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'empresa_usuario', 'empresa_id', 'usuario_id')->withTimestamps();
    }

    /**
     * @return HasMany<Sucursal, $this>
     */
    public function sucursales(): HasMany
    {
        return $this->hasMany(Sucursal::class);
    }

    /**
     * Sucursales activas de la empresa. La condición replica `Sucursal::scopeActivas`
     * y es la única definición de "sucursal activa" usada en los contadores.
     *
     * @return HasMany<Sucursal, $this>
     */
    public function sucursalesActivas(): HasMany
    {
        return $this->hasMany(Sucursal::class)->where('activa', true);
    }

    /**
     * @return HasMany<Colaborador, $this>
     */
    public function colaboradores(): HasMany
    {
        return $this->hasMany(Colaborador::class);
    }

    /**
     * Colaboradores activos de la empresa. La condición replica
     * `Colaborador::scopeActivos` y es la única definición de "colaborador activo"
     * usada en los contadores.
     *
     * @return HasMany<Colaborador, $this>
     */
    public function colaboradoresActivos(): HasMany
    {
        return $this->hasMany(Colaborador::class)->where('activo', true);
    }

    /**
     * @return HasMany<Activo, $this>
     */
    public function activos(): HasMany
    {
        return $this->hasMany(Activo::class);
    }

    /**
     * @return HasMany<Area, $this>
     */
    public function areas(): HasMany
    {
        return $this->hasMany(Area::class);
    }

    /**
     * @return HasMany<Almacen, $this>
     */
    public function almacenes(): HasMany
    {
        return $this->hasMany(Almacen::class);
    }

    /**
     * @return HasMany<EntregaUniforme, $this>
     */
    public function entregas(): HasMany
    {
        return $this->hasMany(EntregaUniforme::class);
    }

    /**
     * @return HasMany<Contrato, $this>
     */
    public function contratos(): HasMany
    {
        return $this->hasMany(Contrato::class);
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
