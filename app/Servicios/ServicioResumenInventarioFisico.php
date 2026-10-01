<?php

namespace App\Servicios;

use App\Enums\EstadoVisibleUnidad;
use App\Enums\FinalidadCustodia;
use App\Models\Almacen;
use App\Models\BitacoraAuditoria;
use App\Models\InventarioFisico;
use App\Models\InventarioFisicoExistencia;
use App\Models\InventarioFisicoUnidad;
use App\Models\MovimientoInventario;
use App\Models\UnidadActivo;
use App\Soporte\FechaHora;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Deriva el resumen de una ronda de inventario físico SIEMPRE desde el
 * snapshot (`inventario_fisico_unidades`), sin columnas redundantes:
 *
 *   esperada && escaneado_en  → Presente (encontrado)
 *   esperada && !escaneado_en → Pendiente de verificar (ronda abierta) /
 *                               Faltante, no localizado (ronda finalizada)
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
        InventarioFisicoUnidad::CLASIFICACION_ENCONTRADO => 'Presente',
        InventarioFisicoUnidad::CLASIFICACION_PENDIENTE => 'Pendiente de verificar',
        InventarioFisicoUnidad::CLASIFICACION_FALTANTE => 'Faltante (no localizado)',
        InventarioFisicoUnidad::CLASIFICACION_NO_ESPERADO => 'No esperado',
    ];

    /**
     * Estados operativos por los que se puede filtrar una ronda: los que una
     * unidad verificable puede tener (el universo nunca espera perdidas,
     * robadas ni de baja).
     *
     * @var list<EstadoVisibleUnidad>
     */
    public const ESTADOS_FILTRABLES = [
        EstadoVisibleUnidad::Disponible,
        EstadoVisibleUnidad::Asignado,
        EstadoVisibleUnidad::Reparacion,
        EstadoVisibleUnidad::Inservible,
    ];

    /**
     * Relaciones de un renglón de unidad para la tarjeta: estado operativo
     * y ubicación ACTUALES (almacén o colaborador con su sucursal y servicio),
     * cargadas por eager loading para toda la página — nunca una consulta por
     * tarjeta ni el historial de la unidad.
     *
     * @var list<string>
     */
    public const RELACIONES_FILA = [
        'unidad:id,codigo,activo_id,almacen_id,empresa_id,colaborador_id,estado,condicion',
        'unidad.activo:id,nombre',
        'unidad.almacen:id,nombre',
        'unidad.colaborador:id,nombre_completo,sucursal_id,servicio_actual_id',
        'unidad.colaborador.sucursal:id,nombre',
        'unidad.colaborador.servicioActual:id,nombre',
        'unidad.especificacion',
        'escaneadoPor:id,name',
    ];

    /**
     * Relaciones de un renglón por cantidad (almacén o custodia) para toda la
     * lista — eager loading, nunca una consulta por renglón. El custodio trae
     * su sucursal y servicio ACTUALES sólo como contexto para localizarlo.
     *
     * @var list<string>
     */
    public const RELACIONES_EXISTENCIA = [
        'almacen:id,nombre',
        'colaborador:id,nombre_completo,numero_empleado,sucursal_id,servicio_actual_id',
        'colaborador.sucursal:id,nombre',
        'colaborador.servicioActual:id,nombre',
        'activo:id,nombre',
        'talla:id,valor',
        'verificadaPor:id,name',
    ];

    /**
     * @var array<string, string>
     */
    public const RESULTADO_ETIQUETA = [
        InventarioFisicoExistencia::RESULTADO_PENDIENTE => 'Pendiente',
        InventarioFisicoExistencia::RESULTADO_COINCIDE => 'Coincide',
        InventarioFisicoExistencia::RESULTADO_FALTANTE => 'Faltante',
        InventarioFisicoExistencia::RESULTADO_SOBRANTE => 'Sobrante',
        InventarioFisicoExistencia::RESULTADO_NO_VERIFICABLE => 'No fue posible verificar',
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
                sum(case when cantidad_contada is null and verificada_en is null then 1 else 0 end) as pendientes,
                sum(case when cantidad_contada is null and verificada_en is not null then 1 else 0 end) as no_verificables,
                sum(case when cantidad_contada is not null and cantidad_contada = cantidad_esperada then 1 else 0 end) as coinciden,
                sum(case when cantidad_contada is not null and cantidad_contada <> cantidad_esperada then 1 else 0 end) as con_diferencia,
                coalesce(sum(cantidad_esperada), 0) as esperada_total,
                coalesce(sum(cantidad_contada), 0) as contada_total,
                sum(case when colaborador_id is not null then 1 else 0 end) as custodia_renglones,
                sum(case when colaborador_id is null and cantidad_contada is not null and cantidad_contada <> cantidad_esperada then 1 else 0 end) as almacen_con_diferencia,
                sum(case when colaborador_id is not null and cantidad_contada is not null and cantidad_contada <> cantidad_esperada then 1 else 0 end) as custodia_con_diferencia
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
            // Pendientes REALES (ni contados ni resueltos como no verificables).
            'cantidad_pendientes' => (int) ($c->pendientes ?? 0),
            // "No fue posible verificar": resueltos sin cantidad (no son 0
            // ni diferencias; nunca se aplican).
            'cantidad_no_verificables' => (int) ($c->no_verificables ?? 0),
            'cantidad_coinciden' => (int) ($c->coinciden ?? 0),
            'cantidad_con_diferencia' => (int) ($c->con_diferencia ?? 0),
            'cantidad_esperada_total' => (int) ($c->esperada_total ?? 0),
            'cantidad_contada_total' => (int) ($c->contada_total ?? 0),
            // Origen de los renglones: sólo las diferencias de ALMACÉN se
            // pueden aplicar al inventario; las de custodia son incidencias
            // a revisar.
            'cantidad_custodia_renglones' => (int) ($c->custodia_renglones ?? 0),
            'cantidad_almacen_con_diferencia' => (int) ($c->almacen_con_diferencia ?? 0),
            'cantidad_custodia_con_diferencia' => (int) ($c->custodia_con_diferencia ?? 0),
        ];
    }

    /**
     * Renglones de comprobación manual de existencias por cantidad de la ronda.
     * `$filtro`: `todos` | `pendientes` | `con_diferencia`. `$origen`:
     * `almacen` | `custodia` | null (ambos). Primero los de almacén, luego los
     * de custodia (en el orden congelado al crear la ronda). Filtrar por
     * almacén deja fuera la custodia: esas piezas ya no están en él.
     *
     * @return Builder<InventarioFisicoExistencia>
     */
    public function consultaExistencias(InventarioFisico $ronda, string $filtro = 'todos', ?int $almacenId = null, ?string $origen = null): Builder
    {
        $consulta = InventarioFisicoExistencia::query()
            ->where('inventario_fisico_id', $ronda->id)
            ->when($almacenId !== null, fn (Builder $q) => $q->where('almacen_id', $almacenId))
            ->when($origen !== null, fn (Builder $q) => $q->deOrigen($origen))
            ->with(self::RELACIONES_EXISTENCIA)
            ->orderByRaw('case when colaborador_id is null then 0 else 1 end')
            ->orderBy('almacen_id')
            ->orderBy('id');

        return match ($filtro) {
            'pendientes' => $consulta->pendientes(),
            'con_diferencia' => $consulta->whereNotNull('cantidad_contada')->whereColumn('cantidad_contada', '<>', 'cantidad_esperada'),
            default => $consulta,
        };
    }

    /**
     * Motivo vigente de cada renglón «No fue posible verificar», leído de la
     * bitácora (`existencia_no_verificable`, escrita en la misma transacción
     * que la resolución). UNA consulta para todos los renglones; el registro
     * más reciente de cada uno es su resolución actual (marcar de nuevo
     * siempre escribe otro).
     *
     * @param  iterable<InventarioFisicoExistencia>  $filas
     * @return array<int, string|null> motivo indexado por id de renglón
     */
    public function motivosNoVerificables(iterable $filas): array
    {
        $ids = [];
        foreach ($filas as $fila) {
            if ($fila->esNoVerificable()) {
                $ids[] = $fila->id;
            }
        }

        if ($ids === []) {
            return [];
        }

        return BitacoraAuditoria::query()
            ->where('tipo_entidad', InventarioFisicoExistencia::class)
            ->where('accion', 'existencia_no_verificable')
            ->whereIn('entidad_id', $ids)
            ->orderBy('id')
            ->get(['id', 'entidad_id', 'valores_nuevos'])
            // Orden ascendente + keyBy: gana el registro más reciente.
            ->keyBy('entidad_id')
            ->map(function (BitacoraAuditoria $b): ?string {
                $motivo = $b->valores_nuevos['motivo_no_verificable'] ?? null;

                return is_string($motivo) ? $motivo : null;
            })
            ->all();
    }

    /**
     * @param  array<int, string|null>  $motivos  de `motivosNoVerificables()`
     * @return array<string, mixed>
     */
    public function filaExistencia(InventarioFisicoExistencia $fila, array $motivos = []): array
    {
        $colaborador = $fila->colaborador;

        return [
            'id' => $fila->id,
            // `almacen` = saldo de un almacén; `custodia` = lo que tenía un
            // colaborador (su diferencia nunca se aplica al inventario).
            'origen' => $fila->origen(),
            'almacen_id' => $fila->almacen_id,
            'almacen' => $fila->almacen?->nombre,
            'custodio' => $colaborador === null ? null : [
                'id' => $colaborador->id,
                'nombre_completo' => $colaborador->nombre_completo,
                'numero_empleado' => $colaborador->numero_empleado,
            ],
            'custodio_sucursal' => $colaborador?->sucursal?->nombre,
            'custodio_servicio' => $colaborador?->servicioActual?->nombre,
            'finalidad' => $fila->finalidad?->value,
            'finalidad_etiqueta' => $fila->esCustodia() ? FinalidadCustodia::etiquetaDe($fila->finalidad) : null,
            'activo' => $fila->activo?->nombre,
            'talla' => $fila->talla?->valor,
            'cantidad_esperada' => $fila->cantidad_esperada,
            'cantidad_contada' => $fila->cantidad_contada,
            'no_verificable' => $fila->esNoVerificable(),
            'motivo_no_verificable' => $motivos[$fila->id] ?? null,
            'diferencia' => $fila->diferencia(),
            'resultado' => $fila->resultado(),
            'verificada_por' => $fila->verificadaPor?->name,
            'verificada_en' => $fila->verificada_en?->toIso8601String(),
        ];
    }

    /**
     * Ubicación legible de un renglón por cantidad: el almacén, o el custodio
     * con su número de empleado. Fuente única para Excel y acta PDF.
     */
    public function ubicacionExistencia(InventarioFisicoExistencia $fila): string
    {
        if (! $fila->esCustodia()) {
            return $fila->almacen->nombre ?? '—';
        }

        $colaborador = $fila->colaborador;

        if ($colaborador === null) {
            return 'Custodia: colaborador no disponible';
        }

        return 'Custodia: '.$colaborador->nombre_completo
            .($colaborador->numero_empleado ? ' ('.$colaborador->numero_empleado.')' : '');
    }

    /**
     * `$seccion` es el estado de VERIFICACIÓN (todos / encontrados = presentes
     * / faltantes = pendientes mientras la ronda está abierta, no localizados
     * al cerrarla / no esperados). `$estadoUnidad` filtra además por el estado
     * OPERATIVO actual de la unidad (En almacén, Asignada, En reparación,
     * Inservible) — dos ejes independientes.
     *
     * @return Builder<InventarioFisicoUnidad>
     */
    public function consultaSeccion(InventarioFisico $ronda, string $seccion, ?EstadoVisibleUnidad $estadoUnidad = null, ?int $almacenId = null): Builder
    {
        $consulta = InventarioFisicoUnidad::query()
            ->where('inventario_fisico_id', $ronda->id)
            // Por almacén = lo que HOY está guardado en él (no las asignadas
            // que sólo salieron de ahí): para recorrer la ronda por zonas.
            ->when($almacenId !== null, fn (Builder $q) => $q->whereIn(
                'unidad_activo_id',
                UnidadActivo::query()->where('almacen_id', $almacenId)->whereNull('colaborador_id')->select('id'),
            ))
            ->when($estadoUnidad !== null, fn (Builder $q) => $q->whereIn(
                'unidad_activo_id',
                UnidadActivo::query()->conEstadoVisible($estadoUnidad)->select('id'),
            ))
            ->with(self::RELACIONES_FILA);

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
    public function filaResumen(InventarioFisicoUnidad $fila, bool $rondaAbierta = false): array
    {
        $unidad = $fila->unidad;
        $clasificacion = $fila->clasificacion($rondaAbierta);
        $colaborador = $unidad?->colaborador;

        return [
            'id' => $fila->id,
            'clasificacion' => $clasificacion,
            'clasificacion_etiqueta' => self::CLASIFICACION_ETIQUETA[$clasificacion] ?? $clasificacion,
            // Ubicación OPERATIVA actual (no la de la ronda): en almacén o en
            // custodia de un colaborador — para saber con quién confirmar.
            'ubicacion' => $colaborador !== null ? 'colaborador' : 'almacen',
            'colaborador_sucursal' => $colaborador?->sucursal?->nombre,
            'colaborador_servicio' => $colaborador?->servicioActual?->nombre,
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
     * Almacenes que aparecen en la ronda (renglones por cantidad + unidades
     * hoy guardadas en ellos) para el filtro "trabajar por almacén". Dos
     * consultas acotadas, nunca una por renglón.
     *
     * @return list<array{id: int, nombre: string}>
     */
    public function almacenesDeLaRonda(InventarioFisico $ronda): array
    {
        $ids = InventarioFisicoExistencia::query()->where('inventario_fisico_id', $ronda->id)->whereNotNull('almacen_id')->distinct()->pluck('almacen_id')
            ->merge(UnidadActivo::query()
                ->whereIn('id', InventarioFisicoUnidad::query()->where('inventario_fisico_id', $ronda->id)->select('unidad_activo_id'))
                ->whereNull('colaborador_id')
                ->distinct()
                ->pluck('almacen_id'))
            ->unique()->values();

        return array_values(Almacen::query()->whereIn('id', $ids)->orderBy('nombre')->get(['id', 'nombre'])
            ->map(fn (Almacen $a): array => ['id' => $a->id, 'nombre' => $a->nombre])->all());
    }

    /**
     * Estado de la APLICACIÓN de correcciones — deliberadamente separado del
     * estado de la ronda (`ronda.estado` sigue siendo sólo
     * en_proceso/finalizado): "aplicadas" > "pendientes" > "sin
     * diferencias", nunca se mezclan. Única fuente para el detalle y el PDF.
     *
     * @param  array<string, int>  $contadores
     *                                          Sólo las diferencias de ALMACÉN son aplicables; las de custodia viajan
     *                                          aparte (`diferencias_custodia`) como incidencias a revisar.
     * @return array{estado: string, total_diferencias: int, diferencias_custodia: int, aplicadas_en: string|null, aplicadas_por: string|null, total_aplicadas: int|null}
     */
    public function resumenCorrecciones(InventarioFisico $ronda, array $contadores): array
    {
        $ronda->loadMissing('correccionesAplicadasPor:id,name');

        return [
            'estado' => match (true) {
                $ronda->tieneCorreccionesAplicadas() => 'aplicadas',
                $contadores['cantidad_almacen_con_diferencia'] === 0 => 'sin_diferencias',
                default => 'pendientes',
            },
            'total_diferencias' => $contadores['cantidad_almacen_con_diferencia'],
            'diferencias_custodia' => $contadores['cantidad_custodia_con_diferencia'],
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
     *     existencias: array<int, array<string, bool|int|string|null>>,
     *     firma: array<string, string>|null,
     *     correcciones: array{estado: string, total_diferencias: int, diferencias_custodia: int, aplicadas_en: string|null, aplicadas_por: string|null, total_aplicadas: int|null},
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
                'clasificacion' => self::CLASIFICACION_ETIQUETA[$f->clasificacion($ronda->estaEnProceso())] ?? $f->clasificacion($ronda->estaEnProceso()),
                'codigo' => $f->unidad?->codigo,
                'activo' => $f->unidad?->activo?->nombre,
                'escaneado_en' => $f->escaneado_en !== null ? FechaHora::local($f->escaneado_en) : null,
                'escaneado_por' => $f->escaneadoPor?->name,
            ])->all();

        $filasExistencia = $this->consultaExistencias($ronda)->get();
        $motivos = $this->motivosNoVerificables($filasExistencia);

        $existencias = $filasExistencia
            ->map(fn (InventarioFisicoExistencia $e): array => [
                'origen' => $e->origen(),
                'almacen' => $e->almacen?->nombre,
                'ubicacion' => $this->ubicacionExistencia($e),
                'finalidad' => $e->esCustodia() ? FinalidadCustodia::etiquetaDe($e->finalidad) : null,
                'activo' => $e->activo?->nombre,
                'talla' => $e->talla?->valor,
                'cantidad_esperada' => $e->cantidad_esperada,
                'cantidad_contada' => $e->cantidad_contada,
                'no_verificable' => $e->esNoVerificable(),
                'motivo_no_verificable' => $motivos[$e->id] ?? null,
                'diferencia' => $e->diferencia(),
                'resultado' => self::RESULTADO_ETIQUETA[$e->resultado()] ?? $e->resultado(),
                'verificada_por' => $e->verificadaPor?->name,
                'verificada_en' => $e->verificada_en !== null ? FechaHora::local($e->verificada_en) : null,
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
