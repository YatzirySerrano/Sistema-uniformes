<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Evidencia inmutable de una devolución confirmada. Dos firmas desde el
 * inicio: la de quien devuelve (colaborador, columnas base) y la del
 * encargado que recibe la devolución (columnas `_operador`). El
 * consentimiento ("aceptacion_titular") lo firma quien recibe la custodia
 * del bien devuelto: el encargado.
 *
 * @property int $id
 * @property string $folio
 * @property int $devolucion_id
 * @property int $empresa_id
 * @property int $sucursal_id
 * @property int $colaborador_id
 * @property int|null $usuario_id
 * @property string $nombre_firmante_snapshot
 * @property string $numero_empleado_snapshot
 * @property string $ruta_firma
 * @property string $hash_firma
 * @property string|null $nombre_firmante_operador_snapshot
 * @property string|null $ruta_firma_operador
 * @property string|null $hash_firma_operador
 * @property bool $aceptacion_titular
 * @property string|null $texto_aceptado_snapshot
 * @property Carbon|null $aceptado_en
 * @property Carbon $firmado_en
 * @property string|null $ruta_pdf
 * @property array<string, mixed> $snapshot_devolucion
 * @property string $hash_documento
 */
class AcuseDevolucion extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'acuses_devolucion';

    protected $fillable = [
        'folio',
        'devolucion_id',
        'empresa_id',
        'sucursal_id',
        'colaborador_id',
        'usuario_id',
        'nombre_firmante_snapshot',
        'numero_empleado_snapshot',
        'ruta_firma',
        'hash_firma',
        'nombre_firmante_operador_snapshot',
        'ruta_firma_operador',
        'hash_firma_operador',
        'aceptacion_titular',
        'texto_aceptado_snapshot',
        'aceptado_en',
        'firmado_en',
        'ip_firma',
        'user_agent_firma',
        'ruta_pdf',
        'snapshot_devolucion',
        'hash_documento',
    ];

    protected function casts(): array
    {
        return [
            'firmado_en' => 'datetime',
            'aceptado_en' => 'datetime',
            'aceptacion_titular' => 'boolean',
            'snapshot_devolucion' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Devolucion, $this>
     */
    public function devolucion(): BelongsTo
    {
        return $this->belongsTo(Devolucion::class);
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
        return 'acuse-devolucion-'.$this->folio.'.pdf';
    }
}
