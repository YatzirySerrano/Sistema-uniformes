<?php

namespace App\Models;

use Database\Factories\EvidenciaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Evidencia fotográfica OPCIONAL de un renglón de entrega o devolución
 * (`evidenciable` → `DetalleEntrega` / `DetalleDevolucion`). El archivo vive
 * siempre en disco privado; esta fila sólo guarda la referencia y los
 * metadatos verificados en el servidor (mime real, peso, hash). Una vez
 * confirmada la entrega/devolución es contenido histórico: no se borra ni se
 * reemplaza por operaciones normales.
 *
 * @property int $id
 * @property string $evidenciable_type
 * @property int $evidenciable_id
 * @property string $disco
 * @property string $ruta
 * @property string $nombre_original
 * @property string $mime
 * @property string $extension
 * @property int $peso_bytes
 * @property string $hash_sha256
 * @property string $origen camara | archivo
 * @property int|null $subido_por
 */
class Evidencia extends Model
{
    /** @use HasFactory<EvidenciaFactory> */
    use HasFactory;

    public const ORIGEN_CAMARA = 'camara';

    public const ORIGEN_ARCHIVO = 'archivo';

    protected $table = 'evidencias';

    protected $fillable = [
        'evidenciable_type',
        'evidenciable_id',
        'disco',
        'ruta',
        'nombre_original',
        'mime',
        'extension',
        'peso_bytes',
        'hash_sha256',
        'origen',
        'subido_por',
    ];

    protected function casts(): array
    {
        return [
            'peso_bytes' => 'integer',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function evidenciable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function subidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subido_por');
    }
}
