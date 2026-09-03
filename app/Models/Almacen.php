<?php

namespace App\Models;

use Database\Factories\AlmacenFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Almacén físico. Abastece a una o varias EMPRESAS / razones sociales (N:M vía
 * `almacen_empresa`); el inventario se mantiene separado por empresa dentro del
 * almacén (`saldos_inventario.empresa_id` + `almacen_id`). El almacén NO
 * pertenece a una empresa y NO se relaciona con sucursales.
 *
 * Desactivar un almacén sólo lo saca de operaciones (para todas sus empresas);
 * no toca el catálogo de activos ni los históricos.
 *
 * @property int $id
 * @property string $nombre
 * @property string|null $codigo
 * @property string|null $descripcion
 * @property string|null $direccion
 * @property string|null $telefono
 * @property string|null $correo
 * @property int|null $responsable_colaborador_id
 * @property bool $activo
 */
class Almacen extends Model
{
    /** @use HasFactory<AlmacenFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'almacenes';

    protected $fillable = [
        'nombre',
        'codigo',
        'descripcion',
        'direccion',
        'telefono',
        'correo',
        'responsable_colaborador_id',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    /**
     * Empresas / razones sociales que este almacén abastece.
     *
     * @return BelongsToMany<Empresa, $this>
     */
    public function empresas(): BelongsToMany
    {
        return $this->belongsToMany(Empresa::class, 'almacen_empresa')
            ->withTimestamps()
            ->orderBy('empresas.nombre_comercial');
    }

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function responsable(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'responsable_colaborador_id');
    }

    /**
     * @return HasMany<SaldoInventario, $this>
     */
    public function saldos(): HasMany
    {
        return $this->hasMany(SaldoInventario::class);
    }

    /**
     * @return HasMany<MovimientoInventario, $this>
     */
    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoInventario::class);
    }

    public function abasteceEmpresa(int $empresaId): bool
    {
        return $this->empresas()->whereKey($empresaId)->exists();
    }

    /**
     * Almacenes que abastecen a la empresa indicada.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeParaEmpresa(Builder $query, int $empresaId): Builder
    {
        return $query->whereHas('empresas', fn (Builder $q) => $q->whereKey($empresaId));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }
}
