<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Foto OPCIONAL 1:1 de una `UnidadActivo` — su identidad física actual, NUNCA
 * evidencia de un evento (eso es `Evidencia`, adjunta a `DetalleEntrega`/
 * `DetalleDevolucion`). Disco privado; el archivo se sirve sólo por el
 * endpoint autorizado (`UnidadActivoController::imagen()`), nunca por URL
 * directa. Reemplazar/eliminar no cambia nada de la unidad dueña.
 *
 * @property int $id
 * @property int $unidad_activo_id
 * @property string $disco
 * @property string $ruta
 * @property string $nombre_original
 * @property string $mime
 * @property string $extension
 * @property int $peso_bytes
 * @property string $hash_sha256
 * @property int|null $subido_por
 */
class ImagenUnidadActivo extends Model
{
    protected $table = 'imagenes_unidad_activo';

    protected $fillable = [
        'unidad_activo_id',
        'disco',
        'ruta',
        'nombre_original',
        'mime',
        'extension',
        'peso_bytes',
        'hash_sha256',
        'subido_por',
    ];

    protected function casts(): array
    {
        return [
            'peso_bytes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<UnidadActivo, $this>
     */
    public function unidadActivo(): BelongsTo
    {
        return $this->belongsTo(UnidadActivo::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function subidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subido_por');
    }
}
