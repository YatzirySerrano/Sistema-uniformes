<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contador atómico por empresa + ámbito. Ver `App\Soporte\ServicioGeneradorCodigos`.
 *
 * @property int $id
 * @property int $empresa_id
 * @property string $ambito
 * @property int $ultimo_valor
 */
class SecuenciaCodigo extends Model
{
    protected $table = 'secuencias_codigo';

    protected $fillable = [
        'empresa_id',
        'ambito',
        'ultimo_valor',
    ];

    protected function casts(): array
    {
        return [
            'ultimo_valor' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Empresa, $this>
     */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
