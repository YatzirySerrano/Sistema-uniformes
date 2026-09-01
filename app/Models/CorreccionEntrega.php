<?php

namespace App\Models;

use Database\Factories\CorreccionEntregaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $entrega_uniforme_id
 * @property int $corregida_por
 * @property string $motivo
 * @property array<string, mixed> $valores_anteriores
 * @property array<string, mixed> $valores_nuevos
 */
class CorreccionEntrega extends Model
{
    /** @use HasFactory<CorreccionEntregaFactory> */
    use HasFactory;

    protected $table = 'correcciones_entrega';

    protected $fillable = [
        'entrega_uniforme_id',
        'corregida_por',
        'motivo',
        'valores_anteriores',
        'valores_nuevos',
    ];

    protected function casts(): array
    {
        return [
            'valores_anteriores' => 'array',
            'valores_nuevos' => 'array',
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
     * @return BelongsTo<User, $this>
     */
    public function corregidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corregida_por');
    }
}
