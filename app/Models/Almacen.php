<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use Database\Factories\AlmacenFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Almacén físico de una empresa. Abastece a una o varias sucursales (N:M) y
 * será la unidad de administración del inventario en un bloque posterior.
 * Desactivar un almacén NO afecta el catálogo global de activos ni los
 * históricos; sólo lo deja fuera de operaciones.
 *
 * @property int $id
 * @property int $empresa_id
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
    use HasFactory, PerteneceAEmpresa, SoftDeletes;

    protected $table = 'almacenes';

    protected $fillable = [
        'empresa_id',
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
     * Sucursales que este almacén abastece.
     *
     * @return BelongsToMany<Sucursal, $this>
     */
    public function sucursales(): BelongsToMany
    {
        return $this->belongsToMany(Sucursal::class, 'almacen_sucursal')
            ->withTimestamps()
            ->orderBy('sucursales.nombre');
    }

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function responsable(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'responsable_colaborador_id');
    }

    /**
     * Saldos de inventario que viven en este almacén.
     *
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

    /**
     * ¿Este almacén abastece a la sucursal indicada?
     */
    public function abasteceSucursal(int $sucursalId): bool
    {
        return $this->sucursales()->whereKey($sucursalId)->exists();
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
