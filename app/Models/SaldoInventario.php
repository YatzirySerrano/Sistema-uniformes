<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use Database\Factories\SaldoInventarioFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Saldo actual de inventario. Tabla de ESTADO ACTUAL, llaveada por
 * `empresa + ALMACÉN + activo + talla` (único `saldos_inv_almacen_unico`).
 * Se actualiza únicamente a través de App\Servicios\ServicioInventario.
 *
 * La procedencia por sucursal vive sólo en el historial
 * (`movimientos_inventario.sucursal_id`), nunca aquí.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $almacen_id
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
}
