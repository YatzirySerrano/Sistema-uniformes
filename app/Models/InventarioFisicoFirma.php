<?php

namespace App\Models;

use Database\Factories\InventarioFisicoFirmaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Firma de conformidad de quien cerró una ronda de inventario físico. 1:1 con
 * la ronda: la fila SÓLO existe si el cierre transaccional terminó bien. La
 * imagen está en disco privado; aquí se guardan su huella SHA-256 y el texto
 * de consentimiento congelado.
 *
 * @property int $id
 * @property int $inventario_fisico_id
 * @property string $ruta_firma
 * @property string $hash_firma
 * @property string $nombre_firmante
 * @property string $texto_aceptado
 * @property Carbon $aceptado_en
 * @property int|null $firmado_por
 */
class InventarioFisicoFirma extends Model
{
    /** @use HasFactory<InventarioFisicoFirmaFactory> */
    use HasFactory;

    protected $table = 'inventario_fisico_firmas';

    protected $fillable = [
        'inventario_fisico_id',
        'ruta_firma',
        'hash_firma',
        'nombre_firmante',
        'texto_aceptado',
        'aceptado_en',
        'firmado_por',
    ];

    protected function casts(): array
    {
        return [
            'aceptado_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<InventarioFisico, $this>
     */
    public function inventarioFisico(): BelongsTo
    {
        return $this->belongsTo(InventarioFisico::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function firmadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'firmado_por');
    }
}
