<?php

namespace App\Models;

use App\Enums\TipoIncidenciaCustodia;
use App\Models\Concerns\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Robo / pérdida de un activo POR CANTIDAD que estaba bajo custodia de un
 * colaborador (entregado y todavía no devuelto). Historia append-only: no se
 * edita ni se borra mediante operaciones normales del sistema. Nunca mueve
 * `saldos_inventario` — las piezas ya habían salido del almacén desde la
 * entrega — sólo reduce el pendiente de custodia del renglón de origen (ver
 * `App\Servicios\ServicioCustodiaColaborador::pendientesPorDetalle()`).
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $colaborador_id
 * @property int $entrega_uniforme_id
 * @property int $detalle_entrega_id
 * @property int $activo_id
 * @property int|null $talla_id
 * @property string $activo_nombre_snapshot
 * @property string|null $talla_valor_snapshot
 * @property TipoIncidenciaCustodia $tipo
 * @property int $cantidad
 * @property string $motivo
 * @property string|null $observacion
 * @property int|null $registrado_por
 */
class IncidenciaCustodia extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'incidencias_custodia';

    protected $fillable = [
        'empresa_id',
        'colaborador_id',
        'entrega_uniforme_id',
        'detalle_entrega_id',
        'activo_id',
        'talla_id',
        'activo_nombre_snapshot',
        'talla_valor_snapshot',
        'tipo',
        'cantidad',
        'motivo',
        'observacion',
        'registrado_por',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoIncidenciaCustodia::class,
            'cantidad' => 'integer',
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
     * @return BelongsTo<EntregaUniforme, $this>
     */
    public function entrega(): BelongsTo
    {
        return $this->belongsTo(EntregaUniforme::class, 'entrega_uniforme_id');
    }

    /**
     * @return BelongsTo<DetalleEntrega, $this>
     */
    public function detalleEntrega(): BelongsTo
    {
        return $this->belongsTo(DetalleEntrega::class);
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
     * @return BelongsTo<User, $this>
     */
    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
