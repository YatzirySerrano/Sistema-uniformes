<?php

namespace App\Models;

use App\Enums\TipoReserva;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Apartado TEMPORAL (TTL) de inventario mientras un usuario prepara una
 * Entrega o una Devolución — nunca la operación en sí. Ver migración para el
 * detalle del dominio ENTREGA vs DEVOLUCIÓN.
 *
 * Activa sólo mientras `scopeActiva()` — nunca se confía en borrar la fila a
 * tiempo; toda consulta de disponibilidad debe pasar por este scope.
 *
 * @property int $id
 * @property string $token
 * @property TipoReserva $tipo
 * @property int $user_id
 * @property int $empresa_id
 * @property int|null $almacen_id
 * @property int|null $colaborador_id
 * @property int|null $entrega_uniforme_id
 * @property Carbon $expira_en
 * @property Carbon|null $consumida_en
 * @property Carbon|null $liberada_en
 */
class Reserva extends Model
{
    protected $table = 'reservas_inventario';

    /** Duración de una reserva nueva o recién extendida. */
    public const int DURACION_MINUTOS = 10;

    protected $fillable = [
        'token',
        'tipo',
        'user_id',
        'empresa_id',
        'almacen_id',
        'colaborador_id',
        'entrega_uniforme_id',
        'expira_en',
        'consumida_en',
        'liberada_en',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoReserva::class,
            'expira_en' => 'datetime',
            'consumida_en' => 'datetime',
            'liberada_en' => 'datetime',
        ];
    }

    /**
     * @return HasMany<RenglonReserva, $this>
     */
    public function renglones(): HasMany
    {
        return $this->hasMany(RenglonReserva::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Empresa, $this>
     */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
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

    public function estaActiva(): bool
    {
        return $this->consumida_en === null
            && $this->liberada_en === null
            && $this->expira_en->isFuture();
    }

    /**
     * Reservas vigentes AHORA MISMO: ni consumidas, ni liberadas, ni vencidas.
     * Única fuente de "¿esta reserva bloquea algo?" — toda consulta de
     * disponibilidad efectiva debe filtrar por aquí, nunca reimplementar la
     * condición a mano.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActiva(Builder $query): Builder
    {
        return $query->whereNull('consumida_en')
            ->whereNull('liberada_en')
            ->where('expira_en', '>', now());
    }
}
