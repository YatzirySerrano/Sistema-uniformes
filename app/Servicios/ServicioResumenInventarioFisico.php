<?php

namespace App\Servicios;

use App\Models\InventarioFisico;
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
    public const SECCIONES = ['encontrados', 'faltantes', 'no_esperados'];

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

        return [
            'esperados' => $esperados,
            'encontrados_esperados' => $encontradosEsperados,
            'encontrados' => $encontradosEsperados + $noEsperados,
            'pendientes' => $esperados - $encontradosEsperados,
            'no_esperados' => $noEsperados,
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
            'esperada' => $fila->esperada,
            'escaneado_en' => $fila->escaneado_en?->toDateTimeString(),
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
