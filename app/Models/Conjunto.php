<?php

namespace App\Models;

use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoUnidadActivo;
use App\Enums\TipoControlActivo;
use App\Enums\TipoReserva;
use App\Models\Concerns\PerteneceAEmpresa;
use App\Servicios\ServicioReservas;
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
    public function disponibilidad(int $almacenId, ?array $variantesPorComponente = null, bool $considerarReservas = false, ?string $excluirToken = null): int
    {
        $desglose = $this->desglosePorComponente($almacenId, $variantesPorComponente, $considerarReservas, $excluirToken);

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
    public function desglosePorComponente(int $almacenId, ?array $variantesPorComponente = null, bool $considerarReservas = false, ?string $excluirToken = null): array
    {
        return $this->componentes()->with('activo')->get()
            ->map(fn (ConjuntoComponente $c): array => $this->capacidadComponente($c, $almacenId, $variantesPorComponente, $considerarReservas, $excluirToken))
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
    private function capacidadComponente(ConjuntoComponente $c, int $almacenId, ?array $variantesPorComponente, bool $considerarReservas = false, ?string $excluirToken = null): array
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

            if ($considerarReservas) {
                $existencia = max(0, $existencia - count(app(ServicioReservas::class)->unidadesApartadasPorOtros($activo->id, $almacenId, $excluirToken)));
            }

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

        // Disponibilidad EFECTIVA (modo Entrega, `considerarReservas`): se
        // descuenta lo que OTRAS reservas activas ya apartaron de esta MISMA
        // clave activo+talla — nunca la reserva del propio borrador
        // (`$excluirToken`). Única forma de que el paso 2 muestre a un
        // segundo usuario que la existencia ya fue tomada por el primero.
        if ($considerarReservas) {
            $existencia = max(0, $existencia - app(ServicioReservas::class)->demandaCantidadDeOtros(
                TipoReserva::Entrega, $this->empresa_id, $almacenId, $activo->id, $tallaId, $excluirToken,
            ));
        }

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

    /**
     * Variante REAL que aplica a un componente al expandir un conjunto
     * concreto de una Entrega (fija siempre gana; libre usa la selección del
     * formulario; sin variante = null). Única fuente de verdad — la
     * reutilizan `App\Acciones\CrearEntregaUniforme::expandirConjunto()` y
     * `App\Acciones\ReservarInventarioEntrega` para no divergir.
     *
     * @param  array<int|string, int|string|null>  $variantesElegidas  Mapa `componente_id => talla_id|null`.
     */
    public static function resolverTallaComponente(ConjuntoComponente $componente, array $variantesElegidas): ?int
    {
        return match (true) {
            $componente->talla_id !== null => $componente->talla_id,
            $componente->talla_libre => isset($variantesElegidas[$componente->id]) ? (int) $variantesElegidas[$componente->id] : null,
            default => null,
        };
    }
}
