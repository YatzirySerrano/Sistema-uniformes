<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Decisión sobre UN bien de la custodia revisada en un cambio de servicio:
 * un renglón de entrega por cantidad o una unidad identificada.
 *
 * @property int $id
 * @property int $cambio_servicio_colaborador_id
 * @property int|null $detalle_entrega_id
 * @property int|null $unidad_activo_id
 * @property string $activo_nombre_snapshot
 * @property string|null $talla_valor_snapshot
 * @property string|null $unidad_codigo_snapshot
 * @property int $cantidad_revisada
 * @property int $cantidad_mantener
 * @property int $cantidad_devolver
 * @property int $cantidad_redistribuir
 * @property int|null $destinatario_id
 */
class CambioServicioRenglon extends Model
{
    protected $table = 'cambio_servicio_renglones';

    protected $fillable = [
        'cambio_servicio_colaborador_id',
        'detalle_entrega_id',
        'unidad_activo_id',
        'activo_nombre_snapshot',
        'talla_valor_snapshot',
        'unidad_codigo_snapshot',
        'cantidad_revisada',
        'cantidad_mantener',
        'cantidad_devolver',
        'cantidad_redistribuir',
        'destinatario_id',
    ];

    protected function casts(): array
    {
        return [
            'cantidad_revisada' => 'integer',
            'cantidad_mantener' => 'integer',
            'cantidad_devolver' => 'integer',
            'cantidad_redistribuir' => 'integer',
        ];
    }

    public function esUnidad(): bool
    {
        return $this->unidad_activo_id !== null;
    }

    /**
     * ¿Ya se decidió qué pasa con TODO lo revisado?
     */
    public function estaDecidido(): bool
    {
        return $this->cantidad_mantener + $this->cantidad_devolver + $this->cantidad_redistribuir === $this->cantidad_revisada;
    }

    /**
     * @return BelongsTo<CambioServicioColaborador, $this>
     */
    public function cambio(): BelongsTo
    {
        return $this->belongsTo(CambioServicioColaborador::class, 'cambio_servicio_colaborador_id');
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

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function destinatario(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'destinatario_id');
    }
}
