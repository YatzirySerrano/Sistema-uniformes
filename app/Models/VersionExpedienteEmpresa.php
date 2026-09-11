<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Empresa de ORIGEN de una versión concreta de un documento del expediente
 * (1:1 con `VersionDocumentoExpediente`). Se escribe una sola vez, al crear la
 * versión, con la empresa vigente del colaborador en ese momento; nunca cambia
 * (la versión es inmutable). Es la llave de visibilidad de las categorías
 * empresariales tras un traslado entre empresas.
 *
 * @property int $id
 * @property int $documento_expediente_version_id
 * @property int $empresa_id
 */
class VersionExpedienteEmpresa extends Model
{
    protected $table = 'documento_expediente_version_empresa';

    protected $fillable = [
        'documento_expediente_version_id',
        'empresa_id',
    ];

    /**
     * @return BelongsTo<VersionDocumentoExpediente, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(VersionDocumentoExpediente::class, 'documento_expediente_version_id');
    }

    /**
     * @return BelongsTo<Empresa, $this>
     */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
