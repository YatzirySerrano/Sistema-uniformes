<?php

namespace App\Models;

use Database\Factories\ServicioFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Servicio operativo (puesto de vigilancia) derivado de un Contrato y
 * anclado administrativamente a una Sucursal. Es la ubicación operativa
 * vigente de los colaboradores y el snapshot histórico de sus entregas.
 *
 * Deliberadamente SIN `empresa_id` propio: se deriva siempre de
 * `contrato->empresa_id` (ver `empresaId()`/`empresa()`) para no duplicar el
 * dato y arriesgar que se desincronice del contrato real.
 *
 * @property int $id
 * @property int $contrato_id
 * @property int $sucursal_id
 * @property string $codigo
 * @property string $nombre
 * @property string|null $direccion
 * @property string|null $descripcion
 * @property bool $activo
 */
class Servicio extends Model
{
    /** @use HasFactory<ServicioFactory> */
    use HasFactory;

    protected $table = 'servicios';

    protected $fillable = [
        'contrato_id',
        'sucursal_id',
        'codigo',
        'nombre',
        'direccion',
        'descripcion',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Contrato, $this>
     */
    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class);
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * Colaboradores cuya ubicación operativa VIGENTE es este servicio.
     *
     * @return HasMany<Colaborador, $this>
     */
    public function colaboradoresActuales(): HasMany
    {
        return $this->hasMany(Colaborador::class, 'servicio_actual_id');
    }

    /**
     * Entregas que tienen a este servicio como snapshot histórico.
     *
     * @return HasMany<EntregaUniforme, $this>
     */
    public function entregas(): HasMany
    {
        return $this->hasMany(EntregaUniforme::class);
    }

    /**
     * Id de empresa derivado del contrato — única fuente para que
     * Policy/Request/vistas no repitan `$servicio->contrato->empresa_id`.
     */
    public function empresaId(): int
    {
        return $this->contrato->empresa_id;
    }

    public function empresa(): Empresa
    {
        return $this->contrato->empresa;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }
}
