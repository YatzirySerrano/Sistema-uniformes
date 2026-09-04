<?php

namespace App\Models;

use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoUnidadActivo;
use App\Enums\TipoControlActivo;
use App\Models\Concerns\PerteneceAEmpresa;
use Database\Factories\ConjuntoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Agrupación lógica de Activos (uniforme, kit de cómputo…) de UNA empresa.
 * Es una PLANTILLA: no tiene stock propio. La disponibilidad se calcula en
 * vivo desde el stock real de cada componente (`disponibilidad()`), nunca se
 * persiste un saldo del conjunto.
 *
 * @property int $id
 * @property int $empresa_id
 * @property string $nombre
 * @property string|null $codigo
 * @property string|null $descripcion
 * @property bool $activo
 */
class Conjunto extends Model
{
    /** @use HasFactory<ConjuntoFactory> */
    use HasFactory, PerteneceAEmpresa, SoftDeletes;

    protected $table = 'conjuntos';

    protected $fillable = [
        'empresa_id',
        'nombre',
        'codigo',
        'descripcion',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    /**
     * @return HasMany<ConjuntoComponente, $this>
     */
    public function componentes(): HasMany
    {
        return $this->hasMany(ConjuntoComponente::class);
    }

    /**
     * Cuántos conjuntos completos se pueden armar HOY en el almacén indicado,
     * calculado a partir del stock real de cada componente (nunca un saldo
     * propio). Por cantidad: existencia de la variante fija, o la suma de
     * todas las variantes elegibles si la variante es libre. Por seguimiento
     * individual: unidades entregables (en almacén y funcionando) del activo
     * en ese almacén.
     */
    public function disponibilidad(int $almacenId): int
    {
        $componentes = $this->componentes()->with('activo')->get();

        if ($componentes->isEmpty()) {
            return 0;
        }

        return (int) $componentes->map(function (ConjuntoComponente $c) use ($almacenId): int {
            $activo = $c->activo;

            if ($activo === null || $c->cantidad_requerida <= 0) {
                return 0;
            }

            if ($activo->tipo_control === TipoControlActivo::SeguimientoIndividual) {
                $disponibles = UnidadActivo::query()
                    ->where('activo_id', $activo->id)
                    ->where('almacen_id', $almacenId)
                    ->where('estado', EstadoUnidadActivo::EnAlmacen)
                    ->where('condicion', CondicionUnidadActivo::Funcionando)
                    ->count();

                return intdiv($disponibles, $c->cantidad_requerida);
            }

            $consulta = SaldoInventario::query()
                ->where('empresa_id', $this->empresa_id)
                ->where('almacen_id', $almacenId)
                ->where('activo_id', $activo->id);

            $existencia = $c->talla_libre
                ? (int) $consulta->sum('cantidad')
                : (int) (clone $consulta)->when(
                    $c->talla_id === null,
                    fn (Builder $q) => $q->whereNull('talla_id'),
                    fn (Builder $q) => $q->where('talla_id', $c->talla_id),
                )->value('cantidad');

            return intdiv($existencia, $c->cantidad_requerida);
        })->min();
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
