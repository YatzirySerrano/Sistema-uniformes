<?php

namespace App\Models;

use App\Enums\EstadoDevolucion;
use App\Models\Concerns\PerteneceAEmpresa;
use Database\Factories\DevolucionFactory;
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
 * @property int|null $entrega_uniforme_id
 * @property int $registrada_por
 * @property Carbon $fecha
 * @property EstadoDevolucion $estado
 * @property Carbon|null $confirmada_en
 * @property-read bool $acuse_exists cargado sólo con `withExists('acuse')`
 */
class Devolucion extends Model
{
    /** @use HasFactory<DevolucionFactory> */
    use HasFactory, PerteneceAEmpresa, SoftDeletes;

    protected $table = 'devoluciones';

    protected $fillable = [
        'folio',
        'empresa_id',
        'sucursal_id',
        'almacen_id',
        'colaborador_id',
        'entrega_uniforme_id',
        'registrada_por',
        'fecha',
        'motivo',
        'notas',
        'estado',
        'confirmada_en',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'estado' => EstadoDevolucion::class,
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
     * @return BelongsTo<Almacen, $this>
     */
    public function almacen(): BelongsTo
    {
        return $this->belongsTo(Almacen::class);
    }

    /**
     * @return BelongsTo<EntregaUniforme, $this>
     */
    public function entrega(): BelongsTo
    {
        return $this->belongsTo(EntregaUniforme::class, 'entrega_uniforme_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function registradaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrada_por');
    }

    /**
     * @return HasMany<DetalleDevolucion, $this>
     */
    public function detalles(): HasMany
    {
        return $this->hasMany(DetalleDevolucion::class);
    }

    /**
     * @return HasOne<AcuseDevolucion, $this>
     */
    public function acuse(): HasOne
    {
        return $this->hasOne(AcuseDevolucion::class);
    }

    public function estaConfirmada(): bool
    {
        return $this->estado->estaConfirmada();
    }
}
