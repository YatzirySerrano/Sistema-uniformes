<?php

namespace App\Models;

use App\Enums\FinalidadCustodia;
use Database\Factories\InventarioFisicoExistenciaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Renglón de comprobación MANUAL de una ronda de inventario físico para un
 * activo por cantidad (no serializado). Dos orígenes, nunca mezclados:
 *
 *  - ALMACÉN (`colaborador_id` NULL): saldo de almacén + activo + variante.
 *  - CUSTODIA (`colaborador_id` presente): lo que un colaborador tenía bajo
 *    su custodia de ese activo + variante con UNA finalidad (`finalidad`
 *    NULL = "Sin clasificar"). Sin almacén: la pieza ya salió de él.
 *
 * `cantidad_esperada` es el snapshot congelado al iniciar la ronda;
 * `cantidad_contada` la escribe el encargado al verificar. Estado derivado de
 * columnas existentes (sólo contar escribe `cantidad_contada` y
 * `verificada_en` juntas):
 *
 *  - Pendiente: `cantidad_contada` NULL y `verificada_en` NULL.
 *  - Contado: `cantidad_contada` presente (coincide / faltante / sobrante).
 *  - «No fue posible verificar»: `cantidad_contada` NULL y `verificada_en`
 *    presente (quién = `verificada_por`). NO es un 0 ni una diferencia: nunca
 *    entra a correcciones. Su motivo opcional vive en la bitácora
 *    (`existencia_no_verificable`, escrita en la misma transacción).
 *
 * El resultado se DERIVA, nunca se persiste. El módulo sólo compara: jamás toca
 * `saldos_inventario`.
 *
 * @property int $id
 * @property int $inventario_fisico_id
 * @property int|null $almacen_id almacén del renglón (rondas integrales: uno por almacén + activo + variante); NULL en custodia
 * @property int|null $colaborador_id custodio congelado (NULL = renglón de almacén)
 * @property FinalidadCustodia|null $finalidad finalidad de la custodia congelada (NULL en custodia = sin clasificar)
 * @property int $activo_id
 * @property int|null $talla_id
 * @property int $cantidad_esperada
 * @property int|null $cantidad_contada
 * @property int|null $verificada_por quién contó o resolvió el renglón
 * @property Carbon|null $verificada_en cuándo (y versión para la concurrencia optimista)
 */
class InventarioFisicoExistencia extends Model
{
    /** @use HasFactory<InventarioFisicoExistenciaFactory> */
    use HasFactory;

    public const RESULTADO_PENDIENTE = 'pendiente';

    public const RESULTADO_COINCIDE = 'coincide';

    public const RESULTADO_FALTANTE = 'faltante';

    public const RESULTADO_SOBRANTE = 'sobrante';

    public const RESULTADO_NO_VERIFICABLE = 'no_verificable';

    public const ORIGEN_ALMACEN = 'almacen';

    public const ORIGEN_CUSTODIA = 'custodia';

    protected $table = 'inventario_fisico_existencias';

    protected $fillable = [
        'inventario_fisico_id',
        'almacen_id',
        'colaborador_id',
        'finalidad',
        'activo_id',
        'talla_id',
        'cantidad_esperada',
        'cantidad_contada',
        'verificada_por',
        'verificada_en',
    ];

    protected function casts(): array
    {
        return [
            'cantidad_esperada' => 'integer',
            'cantidad_contada' => 'integer',
            'verificada_en' => 'datetime',
            'finalidad' => FinalidadCustodia::class,
        ];
    }

    /**
     * Renglón de custodia de un colaborador (no representa saldo de almacén:
     * su diferencia nunca se aplica a `saldos_inventario`).
     */
    public function esCustodia(): bool
    {
        return $this->colaborador_id !== null;
    }

    /**
     * @return 'almacen'|'custodia'
     */
    public function origen(): string
    {
        return $this->esCustodia() ? self::ORIGEN_CUSTODIA : self::ORIGEN_ALMACEN;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDeOrigen(Builder $query, string $origen): Builder
    {
        return $origen === self::ORIGEN_CUSTODIA
            ? $query->whereNotNull('colaborador_id')
            : $query->whereNull('colaborador_id');
    }

    public function verificada(): bool
    {
        return $this->cantidad_contada !== null;
    }

    /**
     * «No fue posible verificar»: resuelto (tiene `verificada_en`) pero sin
     * cantidad contada.
     */
    public function esNoVerificable(): bool
    {
        return $this->cantidad_contada === null && $this->verificada_en !== null;
    }

    /**
     * Ya no está pendiente: contado, o resuelto como "No fue posible
     * verificar". Es lo que exige el cierre de la ronda.
     */
    public function resuelta(): bool
    {
        return $this->verificada() || $this->esNoVerificable();
    }

    /**
     * Pendientes REALES: ni contados ni resueltos como no verificables.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePendientes(Builder $query): Builder
    {
        return $query->whereNull('cantidad_contada')->whereNull('verificada_en');
    }

    public function diferencia(): ?int
    {
        return $this->cantidad_contada === null
            ? null
            : $this->cantidad_contada - $this->cantidad_esperada;
    }

    /**
     * Resultado derivado para el resumen. Nunca se persiste.
     */
    public function resultado(): string
    {
        $diferencia = $this->diferencia();

        return match (true) {
            $this->esNoVerificable() => self::RESULTADO_NO_VERIFICABLE,
            $diferencia === null => self::RESULTADO_PENDIENTE,
            $diferencia === 0 => self::RESULTADO_COINCIDE,
            $diferencia < 0 => self::RESULTADO_FALTANTE,
            default => self::RESULTADO_SOBRANTE,
        };
    }

    /**
     * Almacén al que pertenece el renglón: ahí se cuenta y ahí se aplica la
     * corrección. Los renglones previos lo heredaron de su ronda.
     *
     * @return BelongsTo<Almacen, $this>
     */
    public function almacen(): BelongsTo
    {
        return $this->belongsTo(Almacen::class);
    }

    /**
     * Custodio congelado del renglón. Incluye colaboradores dados de baja: la
     * ronda describe a quién se le pidió comprobar, aunque después cambie.
     *
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class)->withTrashed();
    }

    /**
     * @return BelongsTo<InventarioFisico, $this>
     */
    public function inventarioFisico(): BelongsTo
    {
        return $this->belongsTo(InventarioFisico::class);
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
    public function verificadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verificada_por');
    }
}
