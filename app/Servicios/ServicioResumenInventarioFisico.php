<?php

namespace App\Servicios;

use App\Models\InventarioFisico;
use App\Models\InventarioFisicoExistencia;
use App\Models\InventarioFisicoUnidad;
use App\Models\MovimientoInventario;
use App\Soporte\FechaHora;
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
                'unidad.especificacion',
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
            // Ayuda a reconocer el equipo físico en la ronda (D26).
            'marca_modelo' => $unidad?->especificacion?->marcaModelo(),
            'imei_mascara' => $unidad?->especificacion?->imeiMascara(),
        ];
    }

    /**
     * Estado de la APLICACIÓN de correcciones — deliberadamente separado del
     * estado de la ronda (`ronda.estado` sigue siendo sólo
     * en_proceso/finalizado): "aplicadas" > "pendientes" > "sin
     * diferencias", nunca se mezclan. Única fuente para el detalle y el PDF.
     *
     * @param  array<string, int>  $contadores
     * @return array{estado: string, total_diferencias: int, aplicadas_en: string|null, aplicadas_por: string|null, total_aplicadas: int|null}
     */
    public function resumenCorrecciones(InventarioFisico $ronda, array $contadores): array
    {
        $ronda->loadMissing('correccionesAplicadasPor:id,name');

        return [
            'estado' => match (true) {
                $ronda->tieneCorreccionesAplicadas() => 'aplicadas',
                $contadores['cantidad_con_diferencia'] === 0 => 'sin_diferencias',
                default => 'pendientes',
            },
            'total_diferencias' => $contadores['cantidad_con_diferencia'],
            'aplicadas_en' => $ronda->correcciones_aplicadas_en?->toIso8601String(),
            'aplicadas_por' => $ronda->correccionesAplicadasPor?->name,
            'total_aplicadas' => $ronda->tieneCorreccionesAplicadas()
                ? MovimientoInventario::query()
                    ->where('referencia_tipo', InventarioFisico::class)
                    ->where('referencia_id', $ronda->id)
                    ->count()
                : null,
        ];
    }

    /**
     * Dataset COMPLETO del acta PDF de una ronda: cabecera, contadores, TODAS
     * las unidades registradas (esperadas + no esperadas) y TODOS los
     * renglones por cantidad, firma y correcciones. Ambos bloques se arman
     * siempre, de forma independiente: que uno esté vacío nunca elimina el
     * otro.
     *
     * Histórico: sólo usa lo que la RONDA congeló o registró (snapshot
     * `esperada`, `escaneado_en/por`, `cantidad_esperada/contada`, firma,
     * correcciones). NO imprime el estado/almacén/asignación ACTUAL de cada
     * unidad ni vuelve a leer `saldos_inventario`: eso describiría el
     * presente, no la ronda. Código de unidad, nombre de activo y variante se
     * leen por FK porque la ronda no los duplica (el código es inmutable).
     *
     * @return array{
     *     ronda: array<string, string|null>,
     *     contadores: array<string, int>,
     *     unidades: array<int, array<string, string|null>>,
     *     existencias: array<int, array<string, int|string|null>>,
     *     firma: array<string, string>|null,
     *     correcciones: array{estado: string, total_diferencias: int, aplicadas_en: string|null, aplicadas_por: string|null, total_aplicadas: int|null},
     * }
     */
    public function datosActa(InventarioFisico $ronda): array
    {
        $ronda->loadMissing(['empresa:id,nombre_comercial,logo_ruta', 'almacen:id,nombre', 'usuario:id,name', 'firma']);

        $contadores = $this->contadores($ronda);

        $unidades = InventarioFisicoUnidad::query()
            ->where('inventario_fisico_id', $ronda->id)
            ->with(['unidad:id,codigo,activo_id', 'unidad.activo:id,nombre', 'escaneadoPor:id,name'])
            // Faltantes primero (lo que requiere atención), luego no
            // esperadas y al final las encontradas.
            ->orderByRaw('case when esperada = 1 and escaneado_en is null then 0 when esperada = 0 then 1 else 2 end')
            ->orderBy('id')
            ->get()
            ->map(fn (InventarioFisicoUnidad $f): array => [
                'clasificacion' => self::CLASIFICACION_ETIQUETA[$f->clasificacion()] ?? $f->clasificacion(),
                'codigo' => $f->unidad?->codigo,
                'activo' => $f->unidad?->activo?->nombre,
                'escaneado_en' => $f->escaneado_en !== null ? FechaHora::local($f->escaneado_en) : null,
                'escaneado_por' => $f->escaneadoPor?->name,
            ])->all();

        $etiquetaResultado = [
            InventarioFisicoExistencia::RESULTADO_PENDIENTE => 'Pendiente',
            InventarioFisicoExistencia::RESULTADO_COINCIDE => 'Coincide',
            InventarioFisicoExistencia::RESULTADO_FALTANTE => 'Faltante',
            InventarioFisicoExistencia::RESULTADO_SOBRANTE => 'Sobrante',
        ];

        $existencias = $this->consultaExistencias($ronda)->get()
            ->map(fn (InventarioFisicoExistencia $e): array => [
                'activo' => $e->activo?->nombre,
                'talla' => $e->talla?->valor,
                'cantidad_esperada' => $e->cantidad_esperada,
                'cantidad_contada' => $e->cantidad_contada,
                'diferencia' => $e->diferencia(),
                'resultado' => $etiquetaResultado[$e->resultado()] ?? $e->resultado(),
                'verificada_por' => $e->verificadaPor?->name,
            ])->all();

        $firma = $ronda->firma;

        return [
            'ronda' => [
                'folio' => $ronda->folio,
                'nombre' => $ronda->nombre,
                'empresa' => $ronda->empresa?->nombre_comercial,
                'almacen' => $ronda->almacen?->nombre,
                'responsable' => $ronda->usuario?->name,
                'estado' => $ronda->estado->etiqueta(),
                'observaciones' => $ronda->observaciones,
                'iniciado_en' => $ronda->created_at !== null ? FechaHora::local($ronda->created_at) : null,
                'finalizado_en' => $ronda->finalizado_en !== null ? FechaHora::local($ronda->finalizado_en) : null,
            ],
            'contadores' => $contadores,
            'unidades' => $unidades,
            'existencias' => $existencias,
            'firma' => $firma === null ? null : [
                'nombre_firmante' => $firma->nombre_firmante,
                'aceptado_en' => FechaHora::local($firma->aceptado_en),
                'hash_firma' => $firma->hash_firma,
                'texto_aceptado' => $firma->texto_aceptado,
            ],
            'correcciones' => [
                ...$this->resumenCorrecciones($ronda, $contadores),
                // En el PDF la fecha va ya en zona de presentación.
                'aplicadas_en' => $ronda->correcciones_aplicadas_en !== null ? FechaHora::local($ronda->correcciones_aplicadas_en) : null,
            ],
        ];
    }
}
