<?php

namespace App\Models;

use Database\Factories\DetalleEntregaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $entrega_uniforme_id
 * @property int $prenda_id
 * @property int $talla_id
 * @property int $cantidad
 * @property string $prenda_nombre_snapshot
 * @property string $talla_valor_snapshot
 */
class DetalleEntrega extends Model
{
    /** @use HasFactory<DetalleEntregaFactory> */
    use HasFactory;

    protected $table = 'detalles_entrega';

    protected $fillable = [
        'entrega_uniforme_id',
        'prenda_id',
        'talla_id',
        'cantidad',
        'prenda_nombre_snapshot',
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
     * @return BelongsTo<Prenda, $this>
     */
    public function prenda(): BelongsTo
    {
        return $this->belongsTo(Prenda::class);
    }

    /**
     * @return BelongsTo<Talla, $this>
     */
    public function talla(): BelongsTo
    {
        return $this->belongsTo(Talla::class);
    }
}
