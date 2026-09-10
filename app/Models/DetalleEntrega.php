<?php

namespace App\Models;

use Database\Factories\DetalleEntregaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Renglón real de una entrega: siempre UN componente concreto (por cantidad+
 * variante, o una unidad de seguimiento individual). `conjunto_id` /
 * `conjunto_nombre_snapshot` son sólo procedencia informativa — el renglón
 * sigue moviendo stock real de forma independiente; agregar un activo suelto
 * a una entrega con conjunto NO modifica la definición del conjunto.
 *
 * @property int $id
 * @property int $entrega_uniforme_id
 * @property int $activo_id
 * @property int|null $talla_id
 * @property int|null $unidad_activo_id
 * @property int|null $conjunto_id
 * @property string|null $conjunto_nombre_snapshot
 * @property int $cantidad
 * @property string $activo_nombre_snapshot
 * @property string|null $talla_valor_snapshot
 */
class DetalleEntrega extends Model
{
    /** @use HasFactory<DetalleEntregaFactory> */
    use HasFactory;

    protected $table = 'detalles_entrega';

    protected $fillable = [
        'entrega_uniforme_id',
        'activo_id',
        'talla_id',
        'unidad_activo_id',
        'conjunto_id',
        'conjunto_nombre_snapshot',
        'cantidad',
        'activo_nombre_snapshot',
        'talla_valor_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<EntregaUniforme, $this>
     */
    public function entrega(): BelongsTo
    {
        return $this->belongsTo(EntregaUniforme::class, 'entrega_uniforme_id');
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
     * @return BelongsTo<UnidadActivo, $this>
     */
    public function unidadActivo(): BelongsTo
    {
        return $this->belongsTo(UnidadActivo::class);
    }

    /**
     * @return BelongsTo<Conjunto, $this>
     */
    public function conjunto(): BelongsTo
    {
        return $this->belongsTo(Conjunto::class);
    }

    /**
     * Evidencia fotográfica opcional de este renglón (contenido histórico una
     * vez firmada la entrega).
     *
     * @return MorphMany<Evidencia, $this>
     */
    public function evidencias(): MorphMany
    {
        return $this->morphMany(Evidencia::class, 'evidenciable');
    }
}
