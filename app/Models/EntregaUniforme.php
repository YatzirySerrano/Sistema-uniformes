<?php

namespace App\Models;

use App\Enums\EstadoEntrega;
use App\Models\Concerns\PerteneceAEmpresa;
use Database\Factories\EntregaUniformeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $folio
 * @property int $empresa_id
 * @property int $sucursal_id
 * @property int $colaborador_id
 * @property int $encargado_id
 * @property EstadoEntrega $estado
 * @property Carbon $fecha_entrega
 * @property Carbon|null $confirmada_en
 */
class EntregaUniforme extends Model
{
    /** @use HasFactory<EntregaUniformeFactory> */
    use HasFactory, PerteneceAEmpresa, SoftDeletes;

    protected $table = 'entregas_uniformes';

    protected $fillable = [
        'folio',
        'empresa_id',
        'sucursal_id',
        'almacen_id',
        'colaborador_id',
        'encargado_id',
        'estado',
        'fecha_entrega',
        'notas',
        'confirmada_en',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoEntrega::class,
            'fecha_entrega' => 'date',
            'confirmada_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function encargado(): BelongsTo
    {
        return $this->belongsTo(User::class, 'encargado_id');
    }

    /**
     * @return HasMany<DetalleEntrega, $this>
     */
    public function detalles(): HasMany
    {
        return $this->hasMany(DetalleEntrega::class);
    }

    /**
     * @return HasOne<AcuseRecepcion, $this>
     */
    public function acuse(): HasOne
    {
        return $this->hasOne(AcuseRecepcion::class);
    }

    /**
     * @return HasMany<CorreccionEntrega, $this>
     */
    public function correcciones(): HasMany
    {
        return $this->hasMany(CorreccionEntrega::class);
    }

    public function estaFirmada(): bool
    {
        return $this->estado->estaFirmada();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePendientesDeFirma(Builder $query): Builder
    {
        return $query->where('estado', EstadoEntrega::PendienteFirma->value);
    }
}
