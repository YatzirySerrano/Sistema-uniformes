<?php

namespace App\Models;

use App\Enums\CondicionDevolucion;
use Database\Factories\DetalleDevolucionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $devolucion_id
 * @property int $activo_id
 * @property int $talla_id
 * @property int $cantidad
 * @property CondicionDevolucion $condicion
 * @property bool $reingresa_inventario
 */
class DetalleDevolucion extends Model
{
    /** @use HasFactory<DetalleDevolucionFactory> */
    use HasFactory;

    protected $table = 'detalles_devolucion';

    protected $fillable = [
        'devolucion_id',
        'activo_id',
        'talla_id',
        'cantidad',
        'condicion',
        'reingresa_inventario',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'condicion' => CondicionDevolucion::class,
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
}
