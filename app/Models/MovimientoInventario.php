<?php

namespace App\Models;

use App\Enums\DireccionMovimiento;
use App\Enums\TipoMovimiento;
use App\Models\Concerns\PerteneceAEmpresa;
use Database\Factories\MovimientoInventarioFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Historia append-only de movimientos de inventario. No se edita ni se borra
 * mediante operaciones normales del sistema.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $sucursal_id
 * @property int $activo_id
 * @property int $talla_id
 * @property TipoMovimiento $tipo
 * @property DireccionMovimiento $direccion
 * @property int $cantidad
 * @property int $existencia_anterior
 * @property int $existencia_resultante
 * @property Carbon $ocurrido_en
 */
class MovimientoInventario extends Model
{
    /** @use HasFactory<MovimientoInventarioFactory> */
    use HasFactory, PerteneceAEmpresa;

    protected $table = 'movimientos_inventario';

    protected $fillable = [
        'empresa_id',
        'sucursal_id',
        'activo_id',
        'talla_id',
        'tipo',
        'direccion',
        'cantidad',
        'existencia_anterior',
        'existencia_resultante',
        'referencia_tipo',
        'referencia_id',
        'motivo',
        'notas',
        'realizado_por',
        'ocurrido_en',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoMovimiento::class,
            'direccion' => DireccionMovimiento::class,
            'cantidad' => 'integer',
            'existencia_anterior' => 'integer',
            'existencia_resultante' => 'integer',
            'ocurrido_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * @return BelongsTo<Activo, $this>
     */
    public function activo(): BelongsTo
    {
        return $this->belongsTo(Activo::class);
    }

    /**
     * @return BelongsTo<Talla, $this>
     */
    public function talla(): BelongsTo
    {
        return $this->belongsTo(Talla::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function realizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'realizado_por');
    }
}
