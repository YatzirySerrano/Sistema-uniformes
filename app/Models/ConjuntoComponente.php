<?php

namespace App\Models;

use Database\Factories\ConjuntoComponenteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Línea de un Conjunto: un Activo + cantidad requerida + variante (fija,
 * libre, o ninguna si el activo no usa variantes).
 *
 * @property int $id
 * @property int $conjunto_id
 * @property int $activo_id
 * @property int $cantidad_requerida
 * @property int|null $talla_id
 * @property bool $talla_libre
 */
class ConjuntoComponente extends Model
{
    /** @use HasFactory<ConjuntoComponenteFactory> */
    use HasFactory;

    protected $table = 'conjunto_componentes';

    protected $fillable = [
        'conjunto_id',
        'activo_id',
        'cantidad_requerida',
        'talla_id',
        'talla_libre',
    ];

    protected function casts(): array
    {
        return [
            'cantidad_requerida' => 'integer',
            'talla_libre' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Conjunto, $this>
     */
    public function conjunto(): BelongsTo
    {
        return $this->belongsTo(Conjunto::class);
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
}
