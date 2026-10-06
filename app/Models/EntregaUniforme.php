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
 * @property int|null $almacen_id
 * @property int|null $colaborador_origen_id
 * @property int|null $servicio_id
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
        'colaborador_origen_id',
        'servicio_id',
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
     * @return BelongsTo<Almacen, $this>
     */
    public function almacen(): BelongsTo
    {
        return $this->belongsTo(Almacen::class);
    }

    /**
     * Colaborador custodio del que salieron los bienes cuando la entrega es
     * una REDISTRIBUCIÓN de custodia (no una salida de almacén). NULL en las
     * entregas desde almacén.
     *
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaboradorOrigen(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_origen_id');
    }

    /**
     * ¿Esta entrega redistribuye custodia (colaborador → colaborador) en vez
     * de sacar stock de un almacén? Una redistribución nunca descuenta
     * inventario: los bienes ya salieron del almacén cuando se entregaron al
     * custodio.
     */
    public function esRedistribucion(): bool
    {
        return $this->colaborador_origen_id !== null;
    }

    /**
     * Servicio operativo al momento de la entrega — snapshot HISTÓRICO,
     * nunca se actualiza si el colaborador cambia de servicio después.
     * Nullable: entregas anteriores a este módulo no lo tienen ("Servicio no
     * registrado"), y una entrega interna puede no tener servicio.
     *
     * @return BelongsTo<Servicio, $this>
     */
    public function servicio(): BelongsTo
    {
        return $this->belongsTo(Servicio::class);
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
     * Empresa PROPIETARIA de los bienes de esta entrega (`activos.empresa_id`)
     * — la del inventario al que reingresan al devolverlos. Coincide con
     * `empresa_id` (destino) salvo en una redistribución hacia otra empresa
     * autorizada: ahí el destino es otra razón social pero los bienes siguen
     * siendo de su dueña. Una entrega lleva bienes de una sola propietaria
     * (`RedistribuirCustodia::exigirUnaEmpresaPropietaria`).
     */
    public function empresaInventarioId(): int
    {
        $propietaria = Activo::query()
            ->whereIn('id', $this->detalles()->select('activo_id'))
            ->value('empresa_id');

        return $propietaria !== null ? (int) $propietaria : $this->empresa_id;
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
