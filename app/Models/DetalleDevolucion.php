<?php

namespace App\Models;

use App\Enums\CondicionDevolucion;
use App\Enums\CondicionUnidadActivo;
use Database\Factories\DetalleDevolucionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `detalle_entrega_id` liga esta línea al renglón de la entrega original que
 * la origina — permite acumular "cuánto se ha devuelto ya" de ESE renglón y
 * rechazar devolver más de lo pendiente. `unidad_activo_id` identifica la
 * unidad de seguimiento individual devuelta (en ese caso `talla_id` es nulo y
 * `cantidad` siempre 1).
 *
 * @property int $id
 * @property int $devolucion_id
 * @property int|null $detalle_entrega_id
 * @property int $activo_id
 * @property int|null $talla_id
 * @property int|null $unidad_activo_id
 * @property int $cantidad
 * @property CondicionDevolucion $condicion
 * @property CondicionUnidadActivo|null $condicion_unidad
 * @property bool $reingresa_inventario
 */
class DetalleDevolucion extends Model
{
    /** @use HasFactory<DetalleDevolucionFactory> */
    use HasFactory;

    protected $table = 'detalles_devolucion';

    protected $fillable = [
        'devolucion_id',
        'detalle_entrega_id',
        'activo_id',
        'talla_id',
        'unidad_activo_id',
        'cantidad',
        'condicion',
        'condicion_unidad',
        'reingresa_inventario',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'condicion' => CondicionDevolucion::class,
            'condicion_unidad' => CondicionUnidadActivo::class,
            'reingresa_inventario' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Devolucion, $this>
     */
    public function devolucion(): BelongsTo
    {
        return $this->belongsTo(Devolucion::class);
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
     * @return BelongsTo<DetalleEntrega, $this>
     */
    public function detalleEntrega(): BelongsTo
    {
        return $this->belongsTo(DetalleEntrega::class);
    }

    /**
     * @return BelongsTo<UnidadActivo, $this>
     */
    public function unidadActivo(): BelongsTo
    {
        return $this->belongsTo(UnidadActivo::class);
    }
}
