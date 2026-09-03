<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use Database\Factories\SucursalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $empresa_id
 * @property string $codigo
 * @property string $nombre
 * @property string|null $direccion
 * @property string|null $telefono
 * @property bool $activa
 */
class Sucursal extends Model
{
    /** @use HasFactory<SucursalFactory> */
    use HasFactory, PerteneceAEmpresa, SoftDeletes;

    protected $table = 'sucursales';

    protected $fillable = [
        'empresa_id',
        'codigo',
        'nombre',
        'direccion',
        'telefono',
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
        return $this->belongsToMany(User::class, 'sucursal_usuario', 'sucursal_id', 'usuario_id')->withTimestamps();
    }

    /**
     * @return HasMany<Colaborador, $this>
     */
    public function colaboradores(): HasMany
    {
        return $this->hasMany(Colaborador::class);
    }

    /**
     * Colaboradores activos de la sucursal. La condición replica
     * `Colaborador::scopeActivos` y es la única definición de "colaborador
     * activo" usada en los contadores del módulo Sucursales.
     *
     * @return HasMany<Colaborador, $this>
     */
    public function colaboradoresActivos(): HasMany
    {
        return $this->hasMany(Colaborador::class)->where('activo', true);
    }

    /**
     * @return HasMany<SaldoInventario, $this>
     */
    public function saldos(): HasMany
    {
        return $this->hasMany(SaldoInventario::class);
    }

    /**
     * Almacenes que abastecen a esta sucursal.
     *
     * @return BelongsToMany<Almacen, $this>
     */
    public function almacenes(): BelongsToMany
    {
        return $this->belongsToMany(Almacen::class, 'almacen_sucursal')->withTimestamps();
    }

    /**
     * Almacén activo que abastece a esta sucursal cuando es inequívoco (exactamente
     * uno). Devuelve null si hay cero o varios: en ese caso la operación debe
     * pedir el almacén de forma explícita o resolverse con el asistente.
     */
    public function almacenAbastecedorUnico(): ?Almacen
    {
        $almacenes = $this->almacenes()->where('almacenes.activo', true)->get();

        return $almacenes->count() === 1 ? $almacenes->first() : null;
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
