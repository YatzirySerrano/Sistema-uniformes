<?php

namespace App\Servicios;

use App\Models\InventarioFisico;
use App\Models\InventarioFisicoExistencia;
use App\Models\InventarioFisicoUnidad;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Deriva el resumen de una ronda de inventario físico SIEMPRE desde el
 * snapshot (`inventario_fisico_unidades`), sin columnas redundantes:
 *
 *   esperada && escaneado_en  → Encontrado
 *   esperada && !escaneado_en → Faltante / no localizado
 *   !esperada (siempre escaneado) → Encontrado no esperado
 *
 * Los contadores son UNA sola consulta agregada; las secciones se pagina y se
 * hace eager loading para no incurrir en N+1 aunque la ronda tenga miles de
 * unidades.
 */
class ServicioResumenInventarioFisico
{
    /**
     * Secciones seleccionables en el detalle y en la exportación. `todos` es
     * el universo completo REGISTRADO en la ronda (esperados + no esperados,
     * una fila por unidad — el `UNIQUE(ronda, unidad)` garantiza que no se
     * duplica ninguna).
     */
    public const SECCIONES = ['todos', 'encontrados', 'faltantes', 'no_esperados'];

    /**
     * @var array<string, string>
     */
    private const CLASIFICACION_ETIQUETA = [
        InventarioFisicoUnidad::CLASIFICACION_ENCONTRADO => 'Encontrado',
        InventarioFisicoUnidad::CLASIFICACION_FALTANTE => 'Faltante',
        InventarioFisicoUnidad::CLASIFICACION_NO_ESPERADO => 'No esperado',
    ];

    /**
     * @return array<string, int>
     */
    public function contadores(InventarioFisico $ronda): array
    {
        // Query builder (no Eloquent) para agregados crudos: la fila es un
        // stdClass con los alias, sin propiedades inventadas en el modelo.
        $r = DB::table('inventario_fisico_unidades')
            ->where('inventario_fisico_id', $ronda->id)
            ->selectRaw('
                sum(case when esperada = 1 then 1 else 0 end) as esperados,
                sum(case when esperada = 1 and escaneado_en is not null then 1 else 0 end) as encontrados_esperados,
                sum(case when esperada = 0 then 1 else 0 end) as no_esperados
            ')
            ->first();

        $esperados = (int) ($r->esperados ?? 0);
        $encontradosEsperados = (int) ($r->encontrados_esperados ?? 0);
        $noEsperados = (int) ($r->no_esperados ?? 0);

        // Artículos por cantidad (comprobación manual) — bloque separado, NO se
        // mezcla con el conteo de unidades QR.
        $c = DB::table('inventario_fisico_existencias')
            ->where('inventario_fisico_id', $ronda->id)
            ->selectRaw('
                count(*) as renglones,
                sum(case when cantidad_contada is not null then 1 else 0 end) as verificados,
                sum(case when cantidad_contada is null then 1 else 0 end) as pendientes,
                sum(case when cantidad_contada is not null and cantidad_contada = cantidad_esperada then 1 else 0 end) as coinciden,
                sum(case when cantidad_contada is not null and cantidad_contada <> cantidad_esperada then 1 else 0 end) as con_diferencia,
                coalesce(sum(cantidad_esperada), 0) as esperada_total,
                coalesce(sum(cantidad_contada), 0) as contada_total
            ')
            ->first();

        return [
            // "Todos" = universo REGISTRADO en la ronda: cada unidad es
            // `esperada` o `!esperada` (mutuamente excluyentes, una fila por
            // unidad), así que la suma nunca duplica.
            'todos' => $esperados + $noEsperados,
            'esperados' => $esperados,
            'encontrados_esperados' => $encontradosEsperados,
            'encontrados' => $encontradosEsperados + $noEsperados,
            'pendientes' => $esperados - $encontradosEsperados,
            'no_esperados' => $noEsperados,

            'cantidad_renglones' => (int) ($c->renglones ?? 0),
            'cantidad_verificados' => (int) ($c->verificados ?? 0),
            'cantidad_pendientes' => (int) ($c->pendientes ?? 0),
            'cantidad_coinciden' => (int) ($c->coinciden ?? 0),
            'cantidad_con_diferencia' => (int) ($c->con_diferencia ?? 0),
            'cantidad_esperada_total' => (int) ($c->esperada_total ?? 0),
            'cantidad_contada_total' => (int) ($c->contada_total ?? 0),
        ];
    }

    /**
     * Renglones de comprobación manual de existencias por cantidad de la ronda.
     * `$filtro`: `todos` | `pendientes` | `con_diferencia`.
     *
     * @return Builder<InventarioFisicoExistencia>
     */
    public function consultaExistencias(InventarioFisico $ronda, string $filtro = 'todos'): Builder
    {
        $consulta = InventarioFisicoExistencia::query()
            ->where('inventario_fisico_id', $ronda->id)
            ->with(['activo:id,nombre', 'talla:id,valor', 'verificadaPor:id,name'])
            ->orderBy('id');

        return match ($filtro) {
            'pendientes' => $consulta->whereNull('cantidad_contada'),
            'con_diferencia' => $consulta->whereNotNull('cantidad_contada')->whereColumn('cantidad_contada', '<>', 'cantidad_esperada'),
            default => $consulta,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function filaExistencia(InventarioFisicoExistencia $fila): array
    {
        return [
            'id' => $fila->id,
            'activo' => $fila->activo?->nombre,
            'talla' => $fila->talla?->valor,
            'cantidad_esperada' => $fila->cantidad_esperada,
            'cantidad_contada' => $fila->cantidad_contada,
            'diferencia' => $fila->diferencia(),
            'resultado' => $fila->resultado(),
            'verificada_por' => $fila->verificadaPor?->name,
            'verificada_en' => $fila->verificada_en?->toIso8601String(),
        ];
    }

    /**
     * @return Builder<InventarioFisicoUnidad>
     */
    public function consultaSeccion(InventarioFisico $ronda, string $seccion): Builder
    {
        $consulta = InventarioFisicoUnidad::query()
            ->where('inventario_fisico_id', $ronda->id)
            ->with([
                'unidad:id,codigo,activo_id,almacen_id,empresa_id,colaborador_id,estado,condicion',
                'unidad.activo:id,nombre',
                'unidad.almacen:id,nombre',
                'unidad.colaborador:id,nombre_completo',
                'escaneadoPor:id,name',
            ]);

        return match ($seccion) {
            'todos' => $consulta->orderByRaw('escaneado_en is null desc')->orderByDesc('escaneado_en')->orderBy('id'),
            'encontrados' => $consulta->whereNotNull('escaneado_en')->orderByDesc('escaneado_en'),
            'no_esperados' => $consulta->where('esperada', false)->orderByDesc('escaneado_en'),
            default => $consulta->where('esperada', true)->whereNull('escaneado_en')->orderBy('id'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function filaResumen(InventarioFisicoUnidad $fila): array
    {
        $unidad = $fila->unidad;

        return [
            'id' => $fila->id,
            'clasificacion' => $fila->clasificacion(),
            'clasificacion_etiqueta' => self::CLASIFICACION_ETIQUETA[$fila->clasificacion()] ?? $fila->clasificacion(),
            'esperada' => $fila->esperada,
            'escaneado_en' => $fila->escaneado_en?->toIso8601String(),
            'escaneado_por' => $fila->escaneadoPor?->name,
            'codigo' => $unidad?->codigo,
            'activo' => $unidad?->activo?->nombre,
            'almacen' => $unidad?->almacen?->nombre,
            'colaborador' => $unidad?->colaborador?->nombre_completo,
            'estado_visible' => $unidad?->estadoVisible()->value,
            'estado_visible_etiqueta' => $unidad?->estadoVisible()->etiqueta(),
        ];
    }
}
