<?php

namespace App\Models;

use App\Models\Concerns\NombreNormalizado;
use Database\Factories\TallaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Variante / talla del **catálogo compartido de plataforma** (S, M, 32, 36R,
 * Unitalla…). Ya no pertenece a una empresa: se **habilita por empresa** vía el
 * pivote `talla_empresa`. Un mismo valor ("M") es una sola fila reutilizada por
 * todas las empresas que lo habiliten; su stock sigue separado por
 * `empresa + almacen + activo + talla`.
 *
 * El "sin variante" ya no es una fila comodín: es `talla_id = NULL` en el
 * inventario.
 *
 * @property int $id
 * @property string $valor
 * @property string $valor_normalizado
 * @property int $orden
 * @property bool $activa
 */
class Talla extends Model
{
    /** @use HasFactory<TallaFactory> */
    use HasFactory, NombreNormalizado;

    protected $table = 'tallas';

    protected $fillable = [
        'valor',
        'orden',
        'activa',
    ];

    protected function casts(): array
    {
        return [
            'orden' => 'integer',
            'activa' => 'boolean',
        ];
    }

    public static function columnaNombre(): string
    {
        return 'valor';
    }

    public static function columnaNombreNormalizado(): string
    {
        return 'valor_normalizado';
    }

    /**
     * @return BelongsToMany<Activo, $this>
     */
    public function activos(): BelongsToMany
    {
        return $this->belongsToMany(Activo::class, 'activo_talla')->withTimestamps();
    }

    /**
     * Empresas que tienen habilitada esta variante.
     *
     * @return BelongsToMany<Empresa, $this>
     */
    public function empresas(): BelongsToMany
    {
        return $this->belongsToMany(Empresa::class, 'talla_empresa')->withTimestamps();
    }

    public function habilitadaPara(int $empresaId): bool
    {
        return $this->empresas()->whereKey($empresaId)->exists();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('activa', true);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOrdenadas(Builder $query): Builder
    {
        return $query->orderBy('orden')->orderBy('valor');
    }

    /**
     * Variantes habilitadas para la empresa indicada.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeParaEmpresa(Builder $query, int $empresaId): Builder
    {
        return $query->whereHas('empresas', fn (Builder $q) => $q->whereKey($empresaId));
    }
}
