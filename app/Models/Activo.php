<?php

namespace App\Models;

use App\Enums\TipoControlActivo;
use App\Models\Concerns\PerteneceAEmpresa;
use Database\Factories\ActivoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Activo del catálogo de una empresa. Evolución del antiguo modelo "Prenda":
 * además de uniformes soporta equipos, dispositivos, accesorios, etc.
 *
 * - `tipo_control` = cantidad | serializado (ver App\Enums\TipoControlActivo).
 * - Las variantes/tallas (`tallas()`) son opcionales: un uniforme las usa; una
 *   laptop no.
 * - Este modelo es el CATÁLOGO, no la existencia en un almacén: desactivarlo no
 *   toca inventario ni históricos.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int|null $tipo_activo_id
 * @property int|null $categoria_id
 * @property string $nombre
 * @property string|null $descripcion
 * @property string|null $categoria Espejo temporal del nombre de la categoría (fuente de verdad: categoria_id)
 * @property TipoControlActivo $tipo_control
 * @property string|null $codigo
 * @property string|null $imagen_ruta
 * @property bool $activo
 */
class Activo extends Model
{
    /** @use HasFactory<ActivoFactory> */
    use HasFactory, PerteneceAEmpresa, SoftDeletes;

    protected $table = 'activos';

    protected $fillable = [
        'empresa_id',
        'tipo_activo_id',
        'categoria_id',
        'nombre',
        'descripcion',
        'categoria',
        'tipo_control',
        'codigo',
        'imagen_ruta',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'tipo_control' => TipoControlActivo::class,
        ];
    }

    /**
     * @return BelongsTo<TipoActivo, $this>
     */
    public function tipoActivo(): BelongsTo
    {
        return $this->belongsTo(TipoActivo::class);
    }

    /**
     * Categoría del catálogo. Se llama `categoriaActivo` (no `categoria`) para
     * no colisionar con la columna espejo `activos.categoria`.
     *
     * @return BelongsTo<CategoriaActivo, $this>
     */
    public function categoriaActivo(): BelongsTo
    {
        return $this->belongsTo(CategoriaActivo::class, 'categoria_id');
    }

    /**
     * Variantes/tallas asociadas (opcional según el tipo de activo). Es el
     * conjunto "crudo": incluye variantes que ya no estén habilitadas para la
     * empresa del activo (histórico). Para operar usa `tallasHabilitadas()`.
     *
     * @return BelongsToMany<Talla, $this>
     */
    public function tallas(): BelongsToMany
    {
        return $this->belongsToMany(Talla::class, 'activo_talla')->withTimestamps()->orderBy('tallas.orden');
    }

    /**
     * Variantes que este activo puede USAR en la empresa indicada: asociadas al
     * activo (`activo_talla`) **y** habilitadas para esa empresa
     * (`talla_empresa`) **y** activas globalmente. Es la única fuente de verdad
     * para poblar selectores de variante y validar el inventario.
     *
     * @return Collection<int, Talla>
     */
    public function tallasHabilitadas(int $empresaId): Collection
    {
        return $this->tallas()
            ->where('tallas.activa', true)
            ->whereHas('empresas', fn (Builder $q) => $q->whereKey($empresaId))
            ->orderBy('tallas.orden')
            ->orderBy('tallas.valor')
            ->get();
    }

    /**
     * @return HasMany<SaldoInventario, $this>
     */
    public function saldos(): HasMany
    {
        return $this->hasMany(SaldoInventario::class);
    }

    public function esSerializado(): bool
    {
        return $this->tipo_control === TipoControlActivo::Serializado;
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
