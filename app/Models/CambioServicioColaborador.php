<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Revisión de custodia que acompaña un cambio de servicio de un colaborador
 * que tiene bienes a su cargo (ver `App\Servicios\ServicioCambioServicio`).
 * Sólo guarda el PLAN; si cada bien quedó resuelto se deriva de las
 * entregas/devoluciones reales.
 *
 * @property int $id
 * @property int $colaborador_id
 * @property int $empresa_id
 * @property int|null $servicio_origen_id
 * @property int|null $servicio_destino_id
 * @property string $estado
 * @property string|null $motivo
 * @property int $corte_detalle_entrega_id
 * @property int $corte_detalle_devolucion_id
 * @property int $corte_incidencia_id
 * @property int|null $iniciado_por
 * @property int|null $completado_por
 * @property Carbon|null $completado_en
 * @property int|null $cancelado_por
 * @property Carbon|null $cancelado_en
 * @property Carbon|null $created_at
 */
class CambioServicioColaborador extends Model
{
    public const PENDIENTE = 'pendiente';

    public const COMPLETADO = 'completado';

    public const CANCELADO = 'cancelado';

    protected $table = 'cambios_servicio_colaborador';

    protected $fillable = [
        'colaborador_id',
        'empresa_id',
        'servicio_origen_id',
        'servicio_destino_id',
        'estado',
        'motivo',
        'corte_detalle_entrega_id',
        'corte_detalle_devolucion_id',
        'corte_incidencia_id',
        'iniciado_por',
        'completado_por',
        'completado_en',
        'cancelado_por',
        'cancelado_en',
    ];

    protected function casts(): array
    {
        return [
            'corte_detalle_entrega_id' => 'integer',
            'corte_detalle_devolucion_id' => 'integer',
            'corte_incidencia_id' => 'integer',
            'completado_en' => 'datetime',
            'cancelado_en' => 'datetime',
        ];
    }

    public function estaPendiente(): bool
    {
        return $this->estado === self::PENDIENTE;
    }

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }

    /**
     * @return BelongsTo<Servicio, $this>
     */
    public function servicioOrigen(): BelongsTo
    {
        return $this->belongsTo(Servicio::class, 'servicio_origen_id');
    }

    /**
     * @return BelongsTo<Servicio, $this>
     */
    public function servicioDestino(): BelongsTo
    {
        return $this->belongsTo(Servicio::class, 'servicio_destino_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function iniciadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'iniciado_por');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completado_por');
    }

    /**
     * @return HasMany<CambioServicioRenglon, $this>
     */
    public function renglones(): HasMany
    {
        return $this->hasMany(CambioServicioRenglon::class);
    }
}
