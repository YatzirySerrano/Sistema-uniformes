<?php

namespace App\Models;

use Database\Factories\TraspasoInventarioFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Encabezado de un traspaso de inventario. Mueve existencias de
 * `empresa_origen + almacen_origen` a `empresa_destino + almacen_destino` en
 * una sola operación atómica (ver `App\Acciones\RegistrarTraspasoInventario`).
 *
 * Historia append-only: no se edita ni se borra. NO reemplaza a
 * `movimientos_inventario` — cada renglón sigue generando sus dos movimientos
 * reales (salida en contexto origen, entrada en contexto destino),
 * correlacionados con este encabezado por `referencia_tipo`/`referencia_id`.
 *
 * @property int $id
 * @property string $folio
 * @property string $tipo misma_empresa | interempresa
 * @property int $empresa_origen_id
 * @property int $almacen_origen_id
 * @property int $empresa_destino_id
 * @property int $almacen_destino_id
 * @property string $estado
 * @property string|null $motivo
 * @property string|null $notas
 * @property int|null $realizado_por
 * @property Carbon $ocurrido_en
 */
class TraspasoInventario extends Model
{
    /** @use HasFactory<TraspasoInventarioFactory> */
    use HasFactory;

    public const TIPO_MISMA_EMPRESA = 'misma_empresa';

    public const TIPO_INTEREMPRESA = 'interempresa';

    protected $table = 'traspasos_inventario';

    protected $fillable = [
        'folio',
        'tipo',
        'empresa_origen_id',
        'almacen_origen_id',
        'empresa_destino_id',
        'almacen_destino_id',
        'estado',
        'motivo',
        'notas',
        'realizado_por',
        'ocurrido_en',
    ];

    protected function casts(): array
    {
        return [
            'ocurrido_en' => 'datetime',
        ];
    }

    /**
     * @return HasMany<TraspasoRenglon, $this>
     */
    public function renglones(): HasMany
    {
        return $this->hasMany(TraspasoRenglon::class);
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
     * @return BelongsTo<Almacen, $this>
     */
    public function almacenOrigen(): BelongsTo
    {
        return $this->belongsTo(Almacen::class, 'almacen_origen_id');
    }

    /**
     * @return BelongsTo<Almacen, $this>
     */
    public function almacenDestino(): BelongsTo
    {
        return $this->belongsTo(Almacen::class, 'almacen_destino_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function realizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'realizado_por');
    }

    public function esInterempresa(): bool
    {
        return $this->empresa_origen_id !== $this->empresa_destino_id;
    }
}
