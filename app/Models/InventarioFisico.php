<?php

namespace App\Models;

use App\Enums\EstadoInventarioFisico;
use App\Models\Concerns\PerteneceAEmpresa;
use Database\Factories\InventarioFisicoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Ronda de inventario físico por escaneo de QR (un corte puntual de las
 * unidades de seguimiento individual de UNA empresa). Módulo de VERIFICACIÓN:
 * nunca toca stock, almacén, asignación, condición ni estado de las unidades.
 *
 * El universo esperado se congela al iniciar (`unidades()` con `esperada = true`)
 * y no se recalcula. La clasificación del resumen (encontrado / faltante / no
 * esperado) se deriva de `InventarioFisicoUnidad`, no se persiste.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int|null $usuario_id
 * @property int|null $almacen_id
 * @property string $folio
 * @property string $nombre
 * @property EstadoInventarioFisico $estado
 * @property string|null $observaciones
 * @property Carbon|null $finalizado_en
 */
class InventarioFisico extends Model
{
    /** @use HasFactory<InventarioFisicoFactory> */
    use HasFactory, PerteneceAEmpresa;

    protected $table = 'inventarios_fisicos';

    protected $fillable = [
        'empresa_id',
        'usuario_id',
        'almacen_id',
        'folio',
        'nombre',
        'estado',
        'observaciones',
        'finalizado_en',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoInventarioFisico::class,
            'finalizado_en' => 'datetime',
        ];
    }

    public function estaEnProceso(): bool
    {
        return $this->estado === EstadoInventarioFisico::EnProceso;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /**
     * @return BelongsTo<Almacen, $this>
     */
    public function almacen(): BelongsTo
    {
        return $this->belongsTo(Almacen::class);
    }

    /**
     * @return HasMany<InventarioFisicoUnidad, $this>
     */
    public function unidades(): HasMany
    {
        return $this->hasMany(InventarioFisicoUnidad::class);
    }
}
