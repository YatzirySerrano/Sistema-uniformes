<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Una fila física del historial append-only de un documento del expediente.
 * Nunca se edita ni se borra tras crearse; una "nueva versión" siempre
 * inserta una fila con `version` incrementada, nunca sobrescribe la anterior.
 *
 * @property int $id
 * @property int $documento_expediente_id
 * @property int $version
 * @property string $ruta
 * @property string $nombre_archivo_original
 * @property string $mime
 * @property string $extension
 * @property int $peso_bytes
 * @property string $hash_sha256
 * @property string|null $comentario
 * @property int|null $subido_por
 * @property-read VersionExpedienteEmpresa|null $origenEmpresa
 */
class VersionDocumentoExpediente extends Model
{
    protected $table = 'documento_expediente_versiones';

    protected $fillable = [
        'documento_expediente_id',
        'version',
        'ruta',
        'nombre_archivo_original',
        'mime',
        'extension',
        'peso_bytes',
        'hash_sha256',
        'comentario',
        'subido_por',
    ];

    /**
     * @return BelongsTo<DocumentoExpediente, $this>
     */
    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoExpediente::class, 'documento_expediente_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function subidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subido_por');
    }

    /**
     * Empresa bajo la que se incorporó esta versión (llave de visibilidad de
     * las categorías empresariales tras un traslado).
     *
     * @return HasOne<VersionExpedienteEmpresa, $this>
     */
    public function origenEmpresa(): HasOne
    {
        return $this->hasOne(VersionExpedienteEmpresa::class, 'documento_expediente_version_id');
    }

    /**
     * Id de la empresa de origen, o null si la versión no tiene registro
     * (no debería ocurrir tras el backfill de la migración).
     */
    public function empresaOrigenId(): ?int
    {
        return $this->origenEmpresa?->empresa_id;
    }
}
