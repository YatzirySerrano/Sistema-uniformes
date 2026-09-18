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
     * Cuántos conjuntos completos se pueden armar HOY en el almacén indicado:
     * el MÍNIMO entre la capacidad de cada componente (nunca se suma la
     * capacidad de componentes distintos, y nunca se persiste un saldo
     * propio). Ver `desglosePorComponente()` para el detalle por componente
     * (fuente única también usada por el endpoint de disponibilidad y por la
     * validación de la Entrega).
     *
     * @param  array<int|string, int|string|null>|null  $variantesPorComponente  Selección real de
     *                                                                           variante para los componentes de talla LIBRE (`componente_id =>
     *                                                                           talla_id|null`), tal como la envía el formulario de Entrega.
     *                                                                           `null` (omitido) = modo "plantilla" sin contexto de Entrega: un
     *                                                                           componente de talla libre suma la existencia de TODAS sus
     *                                                                           variantes elegibles (usado por `Conjuntos/Detalle.vue` y por el
     *                                                                           listado inicial de `ConjuntoController::buscar()`, antes de que el
     *                                                                           usuario elija variante). Pasar un array (aunque venga vacío)
     *                                                                           activa el modo "Entrega": un componente de talla libre SIN
     *                                                                           selección en el mapa no puede armarse — su capacidad es 0, NUNCA
     *                                                                           se recurre al agregado de todas las variantes.
     */
    public function disponibilidad(int $almacenId, ?array $variantesPorComponente = null): int
    {
        $desglose = $this->desglosePorComponente($almacenId, $variantesPorComponente);

        if ($desglose === []) {
            return 0;
        }

        return (int) min(array_column($desglose, 'capacidad'));
    }

    /**
     * Desglose de disponibilidad por componente del conjunto, en el almacén
     * indicado. Única fuente de verdad reutilizada por `disponibilidad()`
     * (el mínimo de `capacidad`), por `ConjuntoController::disponibilidad()`
     * (respuesta detallada para la UI de Entrega) y por la validación
     * backend (`GuardarEntregaRequest`, `CrearEntregaUniforme`). Array PLANO
     * (no `Collection`, que es invariante en su generic y dispara falsos
     * positivos de PHPStan en cuanto el resultado viaja por 2+ firmas) —
     * ver `.ai/rules/http-controllers-servicios.md`.
     *
     * @param  array<int|string, int|string|null>|null  $variantesPorComponente  Ver `disponibilidad()`.
     * @return array<int, array{
     *     componente_id: int, activo_id: int, activo_nombre: string|null,
     *     tipo_control: string|null, talla_id: int|null, talla_valor: string|null,
     *     requiere_variante: bool, cantidad_requerida: int, existencia: int|null,
     *     capacidad: int,
     * }>
     */
    public function desglosePorComponente(int $almacenId, ?array $variantesPorComponente = null): array
    {
        return $this->componentes()->with('activo')->get()
            ->map(fn (ConjuntoComponente $c): array => $this->capacidadComponente($c, $almacenId, $variantesPorComponente))
            ->values()
            ->all();
    }

    /**
     * @param  array<int|string, int|string|null>|null  $variantesPorComponente
     * @return array{
     *     componente_id: int, activo_id: int, activo_nombre: string|null,
     *     tipo_control: string|null, talla_id: int|null, talla_valor: string|null,
     *     requiere_variante: bool, cantidad_requerida: int, existencia: int|null,
     *     capacidad: int,
     * }
     */
    private function capacidadComponente(ConjuntoComponente $c, int $almacenId, ?array $variantesPorComponente): array
    {
        $activo = $c->activo;
        $tallaId = $c->talla_id;
        $requiereVariante = false;

        if ($c->talla_libre) {
            if ($variantesPorComponente === null) {
                // Modo "plantilla": sin selección, se agrega más abajo.
                $tallaId = null;
            } else {
                $seleccionada = $variantesPorComponente[$c->id] ?? null;
                $tallaId = $seleccionada !== null ? (int) $seleccionada : null;
                $requiereVariante = $tallaId === null;
            }
        }

        $base = [
            'componente_id' => $c->id,
            'activo_id' => $c->activo_id,
            'activo_nombre' => $activo?->nombre,
            'tipo_control' => $activo?->tipo_control?->value,
            'talla_id' => $tallaId,
            'talla_valor' => $tallaId !== null ? Talla::query()->whereKey($tallaId)->value('valor') : null,
            'requiere_variante' => $requiereVariante,
            'cantidad_requerida' => $c->cantidad_requerida,
        ];

        if ($activo === null || ! $activo->activo || $c->cantidad_requerida <= 0 || $requiereVariante) {
            return [...$base, 'existencia' => null, 'capacidad' => 0];
        }

        if ($activo->tipo_control === TipoControlActivo::SeguimientoIndividual) {
            $existencia = UnidadActivo::query()
                ->where('activo_id', $activo->id)
                ->where('almacen_id', $almacenId)
                ->where('estado', EstadoUnidadActivo::EnAlmacen)
                ->where('condicion', CondicionUnidadActivo::Funcionando)
                ->count();

            return [...$base, 'existencia' => $existencia, 'capacidad' => intdiv($existencia, $c->cantidad_requerida)];
        }

        $consulta = SaldoInventario::query()
            ->where('empresa_id', $this->empresa_id)
            ->where('almacen_id', $almacenId)
            ->where('activo_id', $activo->id);

        // Sólo se suman TODAS las variantes cuando no hay contexto de Entrega
        // (modo "plantilla", `$variantesPorComponente === null`). En modo
        // Entrega, `$tallaId` ya trae la variante realmente seleccionada (o
        // ya se resolvió `requiereVariante` arriba) — nunca se suma stock de
        // una variante distinta a la elegida.
        $existencia = $c->talla_libre && $variantesPorComponente === null
            ? (int) $consulta->sum('cantidad')
            : (int) (clone $consulta)->when(
                $tallaId === null,
                fn (Builder $q) => $q->whereNull('talla_id'),
                fn (Builder $q) => $q->where('talla_id', $tallaId),
            )->value('cantidad');

        return [...$base, 'existencia' => $existencia, 'capacidad' => intdiv($existencia, $c->cantidad_requerida)];
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
