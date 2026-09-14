<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Evidencia inmutable de la firma obligatoria de un traspaso de inventario.
 * 1:1 con `TraspasoInventario` (un traspaso sólo existe ya firmado — no hay
 * estado "pendiente de firma" para traspasos, a diferencia de Entregas y
 * Devoluciones). UNA sola firma: la del responsable que confirma el
 * traspaso. Usa el folio de `traspasoInventario.folio` (TRA-...) para el
 * comprobante; no tiene folio propio.
 *
 * @property int $id
 * @property int $traspaso_inventario_id
 * @property int $empresa_origen_id
 * @property int $empresa_destino_id
 * @property int $firmado_por
 * @property string $nombre_firmante_snapshot
 * @property string $ruta_firma
 * @property string $hash_firma
 * @property Carbon $firmado_en
 * @property string|null $ip_firma
 * @property string|null $user_agent_firma
 * @property array<string, mixed> $snapshot_traspaso
 * @property string $hash_documento
 * @property string|null $ruta_pdf
 */
class AcuseTraspaso extends Model
{
    protected $table = 'acuses_traspaso';

    protected $fillable = [
        'traspaso_inventario_id',
        'empresa_origen_id',
        'empresa_destino_id',
        'firmado_por',
        'nombre_firmante_snapshot',
        'ruta_firma',
        'hash_firma',
        'firmado_en',
        'ip_firma',
        'user_agent_firma',
        'snapshot_traspaso',
        'hash_documento',
        'ruta_pdf',
    ];

    protected function casts(): array
    {
        return [
            'firmado_en' => 'datetime',
            'snapshot_traspaso' => 'array',
        ];
    }

    /**
     * @return BelongsTo<TraspasoInventario, $this>
     */
    public function traspaso(): BelongsTo
    {
        return $this->belongsTo(TraspasoInventario::class, 'traspaso_inventario_id');
    }

    /**
     * @return BelongsTo<Empresa, $this>
     */
    public function empresaOrigen(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_origen_id');
    }

    /**
     * @return BelongsTo<Empresa, $this>
     */
    public function empresaDestino(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_destino_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function firmante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'firmado_por');
    }

    public function tienePdf(): bool
    {
        return $this->ruta_pdf !== null;
    }

    public function nombreArchivoDescarga(): string
    {
        return 'acuse-traspaso-'.$this->traspaso->folio.'.pdf';
    }
}
