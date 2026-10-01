<?php

namespace App\Models;

use Database\Factories\InventarioFisicoUnidadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Renglón de una ronda de inventario físico: la relación (ronda ↔ unidad) más
 * el dato del escaneo. `esperada` distingue si la unidad estaba en el snapshot
 * inicial; `escaneado_en` si ya se encontró físicamente. La clasificación del
 * resumen se DERIVA de esas dos columnas (`clasificacion()`), nunca se guarda.
 *
 * @property int $id
 * @property int $inventario_fisico_id
 * @property int $unidad_activo_id
 * @property bool $esperada
 * @property Carbon|null $escaneado_en
 * @property int|null $escaneado_por
 */
class InventarioFisicoUnidad extends Model
{
    /** @use HasFactory<InventarioFisicoUnidadFactory> */
    use HasFactory;

    public const CLASIFICACION_ENCONTRADO = 'encontrado';

    public const CLASIFICACION_FALTANTE = 'faltante';

    /** Esperada y aún sin verificar mientras la ronda sigue abierta. */
    public const CLASIFICACION_PENDIENTE = 'pendiente';

    public const CLASIFICACION_NO_ESPERADO = 'no_esperado';

    protected $table = 'inventario_fisico_unidades';

    protected $fillable = [
        'inventario_fisico_id',
        'unidad_activo_id',
        'esperada',
        'escaneado_en',
        'escaneado_por',
    ];

    protected function casts(): array
    {
        return [
            'esperada' => 'boolean',
            'escaneado_en' => 'datetime',
        ];
    }

    /**
     * Clasificación derivada para el resumen. Nunca se persiste.
     *
     * Una esperada sin verificar es PENDIENTE mientras la ronda está abierta
     * (todavía nadie la revisó: no es un faltante) y FALTANTE / no localizada
     * sólo cuando la ronda ya se cerró sin verificarla. Mismas columnas de
     * siempre (`esperada`, `escaneado_en`); el estado de la ronda decide.
     */
    public function clasificacion(bool $rondaAbierta = false): string
    {
        if (! $this->esperada) {
            return self::CLASIFICACION_NO_ESPERADO;
        }

        if ($this->escaneado_en !== null) {
            return self::CLASIFICACION_ENCONTRADO;
        }

        return $rondaAbierta ? self::CLASIFICACION_PENDIENTE : self::CLASIFICACION_FALTANTE;
    }

    /**
     * @return BelongsTo<InventarioFisico, $this>
     */
    public function inventarioFisico(): BelongsTo
    {
        return $this->belongsTo(InventarioFisico::class);
    }

    /**
     * @return BelongsTo<UnidadActivo, $this>
     */
    public function unidad(): BelongsTo
    {
        return $this->belongsTo(UnidadActivo::class, 'unidad_activo_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function escaneadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escaneado_por');
    }
}
