<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use Database\Factories\ContratoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Contrato comercial de una empresa (p. ej. "Laboratorios Clínicos Polab"),
 * del que se derivan uno o varios Servicios (puestos operativos). Estructura
 * administrativa: no tiene stock ni participa en el inventario.
 *
 * @property int $id
 * @property int $empresa_id
 * @property string $codigo
 * @property string $nombre
 * @property string|null $descripcion
 * @property Carbon|null $fecha_inicio
 * @property Carbon|null $fecha_fin
 * @property bool $activo
 */
class Contrato extends Model
{
    /** @use HasFactory<ContratoFactory> */
    use HasFactory, PerteneceAEmpresa;

    protected $table = 'contratos';

    protected $fillable = [
        'empresa_id',
        'codigo',
        'nombre',
        'descripcion',
        'fecha_inicio',
        'fecha_fin',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'activo' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Servicio, $this>
     */
    public function servicios(): HasMany
    {
        return $this->hasMany(Servicio::class);
    }

    /**
     * Servicios activos del contrato. Replica `Servicio::scopeActivos` y es
     * la única definición de "servicio activo" usada en los contadores.
     *
     * @return HasMany<Servicio, $this>
     */
    public function serviciosActivos(): HasMany
    {
        return $this->hasMany(Servicio::class)->where('activo', true);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }
}
