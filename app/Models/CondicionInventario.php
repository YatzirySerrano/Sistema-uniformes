<?php

namespace App\Models;

use App\Enums\CondicionDevolucion;
use App\Enums\TipoMovimiento;
use App\Models\Concerns\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historia append-only de condición física de existencias POR CANTIDAD
 * (Dañado / Baja), marcada directamente desde el stock disponible — no
 * desde una Devolución. No se edita ni se borra mediante operaciones
 * normales del sistema; el estado ACTUAL se calcula sumando esta tabla (ver
 * `ServicioEstadoInventario`), nunca se persiste un contador aparte.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $almacen_id
 * @property int $activo_id
 * @property int|null $talla_id
 * @property int $movimiento_inventario_id
 * @property CondicionDevolucion $condicion
 * @property TipoMovimiento $tipo
 * @property int $cantidad
 * @property string $motivo
 * @property int|null $realizado_por
 */
class CondicionInventario extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'condiciones_inventario';

    protected $fillable = [
        'empresa_id',
        'almacen_id',
        'activo_id',
        'talla_id',
        'movimiento_inventario_id',
        'condicion',
        'tipo',
        'cantidad',
        'motivo',
        'realizado_por',
    ];

    protected function casts(): array
    {
        return [
            'condicion' => CondicionDevolucion::class,
            'tipo' => TipoMovimiento::class,
            'cantidad' => 'integer',
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

    /**
     * @return BelongsTo<MovimientoInventario, $this>
     */
    public function movimientoInventario(): BelongsTo
    {
        return $this->belongsTo(MovimientoInventario::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function realizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'realizado_por');
    }
}
