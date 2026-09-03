<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use Database\Factories\SaldoInventarioFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Saldo actual de inventario por empresa + ALMACÉN + activo + talla.
 * Se actualiza únicamente a través de App\Servicios\ServicioInventario.
 *
 * El origen físico del stock es el ALMACÉN. `sucursal_id` sólo aparece en filas
 * legacy aún no migradas (ver "Asistente de migración de existencias") y en el
 * historial; nunca en operación nueva.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int|null $almacen_id
 * @property int|null $sucursal_id Sólo filas legacy pendientes de migración
 * @property int $activo_id
 * @property int $talla_id
 * @property int $cantidad
 * @property int $minimo
 */
class SaldoInventario extends Model
{
    /** @use HasFactory<SaldoInventarioFactory> */
    use HasFactory, PerteneceAEmpresa;

    protected $table = 'saldos_inventario';

    protected $fillable = [
        'empresa_id',
        'almacen_id',
        'sucursal_id',
        'activo_id',
        'talla_id',
        'cantidad',
        'minimo',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'minimo' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Almacen, $this>
     */
    public function almacen(): BelongsTo
    {
        return $this->belongsTo(Almacen::class);
    }

    /**
     * Sólo poblado en filas legacy pendientes de migración a almacén.
     *
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * @return BelongsTo<Activo, $this>
     */
    public function activo(): BelongsTo
    {
        return $this->belongsTo(Activo::class);
    }

    /**
     * @return BelongsTo<Talla, $this>
     */
    public function talla(): BelongsTo
    {
        return $this->belongsTo(Talla::class);
    }

    public function estaBajoMinimo(): bool
    {
        return $this->minimo > 0 && $this->cantidad <= $this->minimo;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeBajoMinimo(Builder $query): Builder
    {
        return $query->where('minimo', '>', 0)->whereColumn('cantidad', '<=', 'minimo');
    }

    /**
     * Filas legacy pendientes de trasladar a un almacén.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePendienteMigracion(Builder $query): Builder
    {
        return $query->whereNull('almacen_id')->whereNotNull('sucursal_id');
    }
}
