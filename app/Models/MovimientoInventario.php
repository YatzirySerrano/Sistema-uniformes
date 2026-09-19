<?php

namespace App\Models;

use App\Enums\CondicionDevolucion;
use App\Enums\DireccionMovimiento;
use App\Enums\TipoMovimiento;
use App\Models\Concerns\PerteneceAEmpresa;
use Database\Factories\MovimientoInventarioFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Historia append-only de movimientos de inventario. No se edita ni se borra
 * mediante operaciones normales del sistema.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int|null $almacen_id
 * @property int|null $sucursal_id Procedencia/contexto; en operación nueva se conserva sólo como referencia
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
        'almacen_id',
        'sucursal_id',
        'activo_id',
        'talla_id',
        'unidad_activo_id',
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
     * @return BelongsTo<Almacen, $this>
     */
    public function almacen(): BelongsTo
    {
        return $this->belongsTo(Almacen::class);
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

    /**
     * @return BelongsTo<UnidadActivo, $this>
     */
    public function unidadActivo(): BelongsTo
    {
        return $this->belongsTo(UnidadActivo::class);
    }

    /**
     * Sólo existe cuando ESTE movimiento vino de un cambio de condición de
     * inventario POR CANTIDAD (`MarcarCondicionInventario` /
     * `RestaurarCondicionInventario`) — nunca para una pérdida/robo real de
     * `UnidadActivo` (`MarcarUnidadIncidencia`, que no crea esta fila). Es la
     * señal estructural que usa `etiquetaEfectiva()` para no confundir ambos
     * casos, aunque compartan `TipoMovimiento`.
     *
     * @return HasOne<CondicionInventario, $this>
     */
    public function condicionInventario(): HasOne
    {
        return $this->hasOne(CondicionInventario::class, 'movimiento_inventario_id');
    }

    /**
     * Etiqueta que debe mostrarse al usuario para ESTE movimiento concreto.
     * `Incidencia`, `Baja` y `Recuperacion` son `TipoMovimiento` compartidos
     * entre dos dominios distintos: una pérdida/robo o baja/recuperación real
     * de una `UnidadActivo`, y un cambio de condición de inventario POR
     * CANTIDAD (Dañado/Baja/Robo-extravío/Restaurado desde el stock
     * disponible de un almacén — Dañado y Robo/extravío comparten a su vez
     * `TipoMovimiento::Incidencia` entre sí). Nunca se distinguen
     * inspeccionando `motivo` (texto libre): se distinguen de forma
     * estructural, mirando la fila de `condiciones_inventario` enlazada a
     * este movimiento (si existe) y su columna `condicion` — sólo el cambio
     * de condición de inventario deja esa fila. Movimientos históricos
     * anteriores a esta distinción ya tenían esa fila desde que se creó el
     * módulo de condición de inventario, así que también se corrigen sin
     * necesidad de reescribir nada.
     */
    public function etiquetaEfectiva(): string
    {
        if (! in_array($this->tipo, [TipoMovimiento::Incidencia, TipoMovimiento::Baja, TipoMovimiento::Recuperacion], true)) {
            return $this->tipo->etiqueta();
        }

        $condicionInventario = $this->relationLoaded('condicionInventario')
            ? $this->condicionInventario
            : $this->condicionInventario()->first();

        if ($condicionInventario === null) {
            return $this->tipo->etiqueta();
        }

        return match ($this->tipo) {
            TipoMovimiento::Incidencia => $condicionInventario->condicion === CondicionDevolucion::RoboExtravio
                ? 'Robo / extravío'
                : 'Marcado como dañado',
            TipoMovimiento::Baja => 'Baja',
            TipoMovimiento::Recuperacion => 'Restauración de piezas dañadas',
        };
    }
}
