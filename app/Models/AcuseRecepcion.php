<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use Database\Factories\AcuseRecepcionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Evidencia inmutable de recepción firmada. El contenido firmado vive en
 * snapshot_entrega y no debe recalcularse a partir de datos actuales.
 *
 * @property int $id
 * @property string $folio
 * @property int $entrega_uniforme_id
 * @property int $empresa_id
 * @property int $sucursal_id
 * @property int $colaborador_id
 * @property string $nombre_firmante_snapshot
 * @property string $numero_empleado_snapshot
 * @property Carbon $firmado_en
 * @property string $ruta_firma
 * @property string|null $nombre_firmante_operador_snapshot
 * @property string|null $ruta_firma_operador
 * @property string|null $hash_firma_operador
 * @property bool $aceptacion_titular
 * @property string|null $texto_aceptado_snapshot
 * @property Carbon|null $aceptado_en
 * @property string|null $ruta_pdf
 * @property array<string, mixed> $snapshot_entrega
 * @property string $hash_documento
 * @property string $hash_firma
 */
class AcuseRecepcion extends Model
{
    /** @use HasFactory<AcuseRecepcionFactory> */
    use HasFactory, PerteneceAEmpresa;

    protected $table = 'acuses_recepcion';

    protected $fillable = [
        'folio',
        'entrega_uniforme_id',
        'empresa_id',
        'sucursal_id',
        'colaborador_id',
        'usuario_id',
        'nombre_firmante_snapshot',
        'numero_empleado_snapshot',
        'firmado_en',
        'ip_firma',
        'user_agent_firma',
        'ruta_firma',
        'nombre_firmante_operador_snapshot',
        'ruta_firma_operador',
        'hash_firma_operador',
        'aceptacion_titular',
        'texto_aceptado_snapshot',
        'aceptado_en',
        'ruta_pdf',
        'snapshot_entrega',
        'hash_documento',
        'hash_firma',
    ];

    protected function casts(): array
    {
        return [
            'firmado_en' => 'datetime',
            'aceptado_en' => 'datetime',
            'aceptacion_titular' => 'boolean',
            'snapshot_entrega' => 'array',
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
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function tienePdf(): bool
    {
        return $this->ruta_pdf !== null;
    }

    public function nombreArchivoDescarga(): string
    {
        return 'acuse-uniformes-'.$this->folio.'.pdf';
    }
}
