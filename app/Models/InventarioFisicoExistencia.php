<?php

namespace App\Models;

use Database\Factories\InventarioFisicoExistenciaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Renglón de comprobación MANUAL de una ronda de inventario físico para un
 * activo por cantidad (no serializado). `cantidad_esperada` es el snapshot
 * congelado del saldo al iniciar la ronda; `cantidad_contada` la escribe el
 * encargado al verificar. El resultado (pendiente / coincide / faltante /
 * sobrante) se DERIVA, nunca se persiste. El módulo sólo compara: jamás toca
 * `saldos_inventario`.
 *
 * @property int $id
 * @property int $inventario_fisico_id
 * @property int $activo_id
 * @property int|null $talla_id
 * @property int $cantidad_esperada
 * @property int|null $cantidad_contada
 * @property int|null $verificada_por
 * @property Carbon|null $verificada_en
 */
class InventarioFisicoExistencia extends Model
{
    /** @use HasFactory<InventarioFisicoExistenciaFactory> */
    use HasFactory;

    public const RESULTADO_PENDIENTE = 'pendiente';

    public const RESULTADO_COINCIDE = 'coincide';

    public const RESULTADO_FALTANTE = 'faltante';

    public const RESULTADO_SOBRANTE = 'sobrante';

    protected $table = 'inventario_fisico_existencias';

    protected $fillable = [
        'inventario_fisico_id',
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
        ];
    }

    public function verificada(): bool
    {
        return $this->cantidad_contada !== null;
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
            $diferencia === null => self::RESULTADO_PENDIENTE,
            $diferencia === 0 => self::RESULTADO_COINCIDE,
            $diferencia < 0 => self::RESULTADO_FALTANTE,
            default => self::RESULTADO_SOBRANTE,
        };
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
