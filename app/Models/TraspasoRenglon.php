<?php

namespace App\Models;

use App\Enums\TipoControlActivo;
use Database\Factories\TraspasoRenglonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Renglón de un traspaso. Control por cantidad: `talla_id` + `cantidad`.
 * Seguimiento individual: UN renglón por unidad física (`unidad_activo_id`,
 * `cantidad = 1`). `activo_destino_id` lo resuelve la acción del traspaso
 * (homologación: reutiliza el activo equivalente de la empresa destino o crea
 * uno nuevo — `activo_destino_creado` lo indica). `movimiento_salida_id` /
 * `movimiento_entrada_id` correlacionan las dos patas reales de
 * `movimientos_inventario`.
 *
 * @property int $id
 * @property int $traspaso_inventario_id
 * @property TipoControlActivo $control
 * @property int $activo_origen_id
 * @property int $activo_destino_id
 * @property int|null $talla_id
 * @property int|null $unidad_activo_id
 * @property int $cantidad
 * @property string $activo_origen_nombre_snapshot
 * @property string $activo_destino_nombre_snapshot
 * @property string|null $talla_valor_snapshot
 * @property bool $activo_destino_creado
 * @property string|null $unidad_codigo_snapshot
 * @property int|null $movimiento_salida_id
 * @property int|null $movimiento_entrada_id
 */
class TraspasoRenglon extends Model
{
    /** @use HasFactory<TraspasoRenglonFactory> */
    use HasFactory;

    protected $table = 'traspaso_inventario_renglones';

    protected $fillable = [
        'traspaso_inventario_id',
        'control',
        'activo_origen_id',
        'activo_destino_id',
        'talla_id',
        'unidad_activo_id',
        'cantidad',
        'activo_origen_nombre_snapshot',
        'activo_destino_nombre_snapshot',
        'talla_valor_snapshot',
        'activo_destino_creado',
        'unidad_codigo_snapshot',
        'movimiento_salida_id',
        'movimiento_entrada_id',
    ];

    protected function casts(): array
    {
        return [
            'control' => TipoControlActivo::class,
            'cantidad' => 'integer',
            'activo_destino_creado' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<TraspasoInventario, $this>
     */
    public function traspaso(): BelongsTo
    {
        return $this->belongsTo(TraspasoInventario::class, 'traspaso_inventario_id');
    }

    /**
     * @return BelongsTo<Activo, $this>
     */
    public function activoOrigen(): BelongsTo
    {
        return $this->belongsTo(Activo::class, 'activo_origen_id');
    }

    /**
     * @return BelongsTo<Activo, $this>
     */
    public function activoDestino(): BelongsTo
    {
        return $this->belongsTo(Activo::class, 'activo_destino_id');
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
}
