<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Línea de una `Reserva`. Cada fila representa exactamente UNA forma sin
 * ambigüedad — ver la migración para el detalle de qué columnas aplican a
 * cada combinación tipo de reserva × cantidad/unidad.
 *
 * @property int $id
 * @property int $reserva_id
 * @property int|null $activo_id
 * @property int|null $talla_id
 * @property int|null $cantidad
 * @property int|null $unidad_activo_id
 * @property int|null $detalle_entrega_id
 */
class RenglonReserva extends Model
{
    protected $table = 'reservas_inventario_renglones';

    protected $fillable = [
        'reserva_id',
        'activo_id',
        'talla_id',
        'cantidad',
        'unidad_activo_id',
        'detalle_entrega_id',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Reserva, $this>
     */
    public function reserva(): BelongsTo
    {
        return $this->belongsTo(Reserva::class);
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
     * @return BelongsTo<UnidadActivo, $this>
     */
    public function unidadActivo(): BelongsTo
    {
        return $this->belongsTo(UnidadActivo::class);
    }

    /**
     * @return BelongsTo<DetalleEntrega, $this>
     */
    public function detalleEntrega(): BelongsTo
    {
        return $this->belongsTo(DetalleEntrega::class);
    }
}
