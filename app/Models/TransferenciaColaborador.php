<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Fila append-only de una transferencia de empresa de un colaborador. Se
 * escribe exclusivamente desde App\Acciones\CambiarEmpresaColaborador.
 *
 * @property int $id
 * @property int $colaborador_id
 * @property int $empresa_origen_id
 * @property int $empresa_destino_id
 * @property int|null $sucursal_origen_id
 * @property int|null $sucursal_destino_id
 * @property int|null $area_origen_id
 * @property int|null $area_destino_id
 * @property int|null $servicio_origen_id
 * @property int|null $servicio_destino_id
 * @property string|null $numero_empleado_anterior
 * @property string $numero_empleado_nuevo
 * @property string|null $motivo
 * @property int|null $usuario_id
 * @property Carbon $ocurrido_en
 */
class TransferenciaColaborador extends Model
{
    protected $table = 'transferencias_colaborador';

    public const UPDATED_AT = null;

    protected $fillable = [
        'colaborador_id',
        'empresa_origen_id',
        'empresa_destino_id',
        'sucursal_origen_id',
        'sucursal_destino_id',
        'area_origen_id',
        'area_destino_id',
        'servicio_origen_id',
        'servicio_destino_id',
        'numero_empleado_anterior',
        'numero_empleado_nuevo',
        'motivo',
        'usuario_id',
        'ocurrido_en',
    ];

    protected function casts(): array
    {
        return [
            'ocurrido_en' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }

    /**
     * @return BelongsTo<Empresa, $this>
     */
    public function empresaOrigen(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_origen_id');
    }

    /**
     * @return BelongsTo<Empresa, $this>
     */
    public function empresaDestino(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_destino_id');
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursalOrigen(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_origen_id');
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursalDestino(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_destino_id');
    }

    /**
     * @return BelongsTo<Area, $this>
     */
    public function areaOrigen(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'area_origen_id');
    }

    /**
     * @return BelongsTo<Area, $this>
     */
    public function areaDestino(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'area_destino_id');
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
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
