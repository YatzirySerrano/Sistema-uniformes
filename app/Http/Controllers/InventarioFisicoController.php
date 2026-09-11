<?php

namespace App\Http\Controllers;

use App\Acciones\CrearRondaInventarioFisico;
use App\Acciones\DesmarcarUnidadPresente;
use App\Acciones\EscanearUnidadInventarioFisico;
use App\Acciones\FinalizarRondaInventarioFisico;
use App\Acciones\MarcarUnidadPresente;
use App\Acciones\VerificarExistenciaInventarioFisico;
use App\Enums\EstadoInventarioFisico;
use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Controllers\Concerns\ExportaListado;
use App\Http\Requests\InventarioFisico\EscanearUnidadRequest;
use App\Http\Requests\InventarioFisico\FinalizarRondaRequest;
use App\Http\Requests\InventarioFisico\GuardarInventarioFisicoRequest;
use App\Http\Requests\InventarioFisico\VerificarExistenciaRequest;
use App\Models\Almacen;
use App\Models\InventarioFisico;
use App\Models\InventarioFisicoExistencia;
use App\Models\InventarioFisicoUnidad;
use App\Models\SaldoInventario;
use App\Servicios\ServicioResumenInventarioFisico;
use App\Soporte\ContextoExportacion;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Inventario físico por rondas de escaneo QR. Módulo de VERIFICACIÓN: compara
 * lo que se encuentra físicamente contra el snapshot del sistema; nunca mueve
 * stock ni cambia almacén / asignación / estado / condición de las unidades.
 *
 * `Ruta → Controller (delgado) → Form Request → Acción/Servicio → Modelo`.
 */
class InventarioFisicoController extends Controller
{
    use ConEmpresa;
    use ExportaListado;

    public function __construct(private readonly ServicioResumenInventarioFisico $resumen) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', InventarioFisico::class);

        $empresaFiltro = $this->empresaDelFiltro($request);
        $filtros = $this->filtrosListado($request);

        $rondas = $this->consultaRondas($request, $filtros)->paginate($this->porPagina())->withQueryString();

        $contadoresPorRonda = $this->contadoresPorRonda($rondas->getCollection()->pluck('id'));

        $rondas->through(fn (InventarioFisico $r): array => [
            'id' => $r->id,
            'folio' => $r->folio,
            'nombre' => $r->nombre,
            'empresa' => $r->empresa?->nombre_comercial,
            'almacen' => $r->almacen?->nombre,
            'responsable' => $r->usuario?->name,
            'estado' => $r->estado->value,
            'estado_etiqueta' => $r->estado->etiqueta(),
            'iniciado_en' => $r->created_at?->toIso8601String(),
            'finalizado_en' => $r->finalizado_en?->toIso8601String(),
            ...$contadoresPorRonda[$r->id] ?? $this->contadoresVacios(),
        ]);

        return Inertia::render('InventarioFisico/Index', [
            'rondas' => $rondas,
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
            'filtros' => [
                'buscar' => $filtros['buscar'] ?? '',
                'empresa_id' => $empresaFiltro?->id,
                'estado' => $filtros['estado'] ?? '',
            ],
            'permisos' => [
                'crear' => $request->user()->can('create', InventarioFisico::class),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', InventarioFisico::class);

        return Inertia::render('InventarioFisico/Crear', [
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
        ]);
    }

    /**
     * Previsualización (no autoritativa) de cuántas unidades entrarán en el
     * snapshot con el alcance elegido. Misma regla exacta que usa el snapshot.
     */
    public function universo(Request $request): JsonResponse
    {
        $this->authorize('create', InventarioFisico::class);

        $empresa = $this->empresaDelFiltro($request);

        if ($empresa === null) {
            return response()->json(['total' => 0]);
        }

        $almacenId = null;

        if ($request->filled('almacen_id')) {
            $almacen = Almacen::query()->find((int) $request->query('almacen_id'));
            $almacenId = ($almacen !== null && $almacen->abasteceEmpresa($empresa->id)) ? $almacen->id : null;
        }

        // El almacén es obligatorio para las rondas nuevas: sin él no hay
        // universo que previsualizar.
        if ($almacenId === null) {
            return response()->json(['total' => 0, 'existencias' => 0]);
        }

        return response()->json([
            'total' => CrearRondaInventarioFisico::universo($empresa->id, $almacenId)->count(),
            'existencias' => SaldoInventario::query()
                ->where('empresa_id', $empresa->id)
                ->where('almacen_id', $almacenId)
                ->where('cantidad', '>', 0)
                ->count(),
        ]);
    }

    public function store(GuardarInventarioFisicoRequest $request, CrearRondaInventarioFisico $accion): RedirectResponse
    {
        $empresa = $request->empresaResuelta();

        $almacen = Almacen::query()->findOrFail($request->integer('almacen_id'));

        $ronda = $accion->ejecutar(
            $empresa,
            $request->string('nombre')->toString(),
            $almacen,
            $request->input('observaciones'),
            $request->user()?->id,
        );

        return to_route('inventarios-fisicos.show', $ronda)
            ->with('toast', ['type' => 'success', 'message' => 'Ronda de inventario físico iniciada.']);
    }

    public function show(Request $request, InventarioFisico $inventarioFisico): Response
    {
        $this->authorize('view', $inventarioFisico);

        $seccion = $this->seccionValida($request);

        $unidades = $this->resumen->consultaSeccion($inventarioFisico, $seccion)
            ->paginate($this->porPagina(), ['*'], 'pagina')
            ->withQueryString()
            ->through(fn (InventarioFisicoUnidad $f): array => $this->resumen->filaResumen($f));

        $inventarioFisico->load(['empresa:id,nombre_comercial', 'almacen:id,nombre', 'usuario:id,name', 'firma:id,inventario_fisico_id,nombre_firmante,hash_firma,aceptado_en']);

        $contadores = $this->resumen->contadores($inventarioFisico);

        // Los renglones de existencias por cantidad de una ronda son pocos (uno
        // por activo+variante del almacén): se sirven completos, sin paginar.
        $existencias = $this->resumen->consultaExistencias($inventarioFisico)->get()
            ->map(fn (InventarioFisicoExistencia $e): array => $this->resumen->filaExistencia($e))
            ->values();

        return Inertia::render('InventarioFisico/Detalle', [
            'ronda' => [
                'id' => $inventarioFisico->id,
                'folio' => $inventarioFisico->folio,
                'nombre' => $inventarioFisico->nombre,
                'estado' => $inventarioFisico->estado->value,
                'estado_etiqueta' => $inventarioFisico->estado->etiqueta(),
                'empresa' => $inventarioFisico->empresa?->nombre_comercial,
                'almacen' => $inventarioFisico->almacen?->nombre,
                'responsable' => $inventarioFisico->usuario?->name,
                'observaciones' => $inventarioFisico->observaciones,
                'iniciado_en' => $inventarioFisico->created_at?->toIso8601String(),
                'finalizado_en' => $inventarioFisico->finalizado_en?->toIso8601String(),
                'firma' => $inventarioFisico->firma === null ? null : [
                    'nombre_firmante' => $inventarioFisico->firma->nombre_firmante,
                    'hash_firma' => $inventarioFisico->firma->hash_firma,
                    'aceptado_en' => $inventarioFisico->firma->aceptado_en->toIso8601String(),
                ],
            ],
            'contadores' => $contadores,
            'seccion' => $seccion,
            'unidades' => $unidades,
            'existencias' => $existencias,
            'textoAceptacion' => FinalizarRondaInventarioFisico::TEXTO_ACEPTACION,
            'permisos' => [
                'administrar' => $request->user()->can('administrar', $inventarioFisico),
                'finalizar' => $request->user()->can('administrar', $inventarioFisico)
                    && $inventarioFisico->estaEnProceso()
                    && $contadores['cantidad_pendientes'] === 0,
            ],
        ]);
    }

    public function verificarExistencia(
        VerificarExistenciaRequest $request,
        InventarioFisico $inventarioFisico,
        InventarioFisicoExistencia $existencia,
        VerificarExistenciaInventarioFisico $accion,
    ): JsonResponse {
        $fila = $accion->ejecutar(
            $inventarioFisico,
            $existencia,
            (int) $request->integer('cantidad_contada'),
            $request->user(),
        );

        return response()->json([
            'existencia' => $this->resumen->filaExistencia($fila),
            'contadores' => $this->resumen->contadores($inventarioFisico),
        ]);
    }

    public function marcarUnidadPresente(
        Request $request,
        InventarioFisico $inventarioFisico,
        InventarioFisicoUnidad $unidad,
        MarcarUnidadPresente $accion,
    ): JsonResponse {
        abort_unless($request->user()->can('administrar', $inventarioFisico), 403);

        $fila = $accion->ejecutar($inventarioFisico, $unidad, $request->user());

        return $this->respuestaFilaUnidad($inventarioFisico, $fila);
    }

    /**
     * Revierte la marca de "presente" de una unidad esperada antes de que la
     * ronda se finalice (corrección de un clic accidental / re-comprobación
     * física). Backend es la autoridad: `DesmarcarUnidadPresente` valida el
     * estado de la ronda y la pertenencia del renglón bajo lock.
     */
    public function desmarcarUnidadPresente(
        Request $request,
        InventarioFisico $inventarioFisico,
        InventarioFisicoUnidad $unidad,
        DesmarcarUnidadPresente $accion,
    ): JsonResponse {
        abort_unless($request->user()->can('administrar', $inventarioFisico), 403);

        $fila = $accion->ejecutar($inventarioFisico, $unidad);

        return $this->respuestaFilaUnidad($inventarioFisico, $fila);
    }

    /**
     * Respuesta JSON común de marcar / desmarcar: la fila actualizada (para que
     * el frontend refleje el estado real sin recargar) + los contadores
     * derivados del servidor (nunca un `++` a ciegas en el cliente).
     */
    private function respuestaFilaUnidad(InventarioFisico $ronda, InventarioFisicoUnidad $fila): JsonResponse
    {
        $fila->loadMissing(['unidad:id,codigo,activo_id,almacen_id,empresa_id,colaborador_id,estado,condicion', 'unidad.activo:id,nombre', 'unidad.almacen:id,nombre', 'unidad.colaborador:id,nombre_completo', 'escaneadoPor:id,name']);

        return response()->json([
            'unidad' => $this->resumen->filaResumen($fila),
            'contadores' => $this->resumen->contadores($ronda),
        ]);
    }

    public function escanear(EscanearUnidadRequest $request, InventarioFisico $inventarioFisico, EscanearUnidadInventarioFisico $accion): JsonResponse
    {
        // La autorización (permiso + acceso a la empresa de la ronda) vive en
        // el Form Request. El `codigo` es texto libre: lo interpreta la acción.
        $payload = $accion->ejecutar(
            $inventarioFisico,
            $request->string('codigo')->toString(),
            $request->user(),
        );

        return response()->json($payload);
    }

    public function finalizar(FinalizarRondaRequest $request, InventarioFisico $inventarioFisico, FinalizarRondaInventarioFisico $accion): RedirectResponse
    {
        // Autorización (permiso + acceso a la empresa) en el Form Request.
        $accion->ejecutar($inventarioFisico, $request->string('firma')->toString(), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Ronda de inventario físico finalizada y firmada.']);
    }

    /**
     * Excel / PDF del LISTADO general de rondas, respetando EXACTAMENTE los
     * mismos filtros (`buscar` / `empresa_id` / `estado`) y alcance multiempresa
     * que `index()` — reutiliza `consultaRondas()`, sin `->paginate()`, así que
     * exporta TODAS las rondas que coinciden, no sólo la página visible.
     */
    public function exportarListado(Request $request): BinaryFileResponse|HttpResponse
    {
        $this->authorize('viewAny', InventarioFisico::class);

        $filtros = $this->filtrosListado($request);
        $empresaFiltro = $this->empresaDelFiltro($request);

        $rondas = $this->consultaRondas($request, $filtros)->get();
        $contadores = $this->contadoresPorRonda($rondas->pluck('id'));

        $filas = $rondas->map(function (InventarioFisico $r) use ($contadores): array {
            $c = $contadores[$r->id] ?? $this->contadoresVacios();

            return [
                $r->folio,
                $r->nombre,
                $r->empresa?->nombre_comercial,
                $r->almacen?->nombre,
                $r->created_at?->format('d/m/Y H:i'),
                $r->usuario?->name,
                $r->estado->etiqueta(),
                $c['esperados'],
                $c['escaneados'],
                $c['faltantes'],
                $c['no_esperados'],
            ];
        })->all();

        $contexto = new ContextoExportacion(
            'Inventarios físicos',
            $empresaFiltro,
            array_filter([
                'Búsqueda' => $filtros['buscar'] ?? null,
                'Estado' => ($filtros['estado'] ?? null) ? EstadoInventarioFisico::from($filtros['estado'])->etiqueta() : null,
            ]),
            count($filas),
        );

        return $this->respuestaExportacion($request->input('formato', 'xlsx'), $filas, [
            'Folio', 'Nombre', 'Empresa', 'Almacén', 'Inicio', 'Responsable', 'Estado',
            'Esperados', 'Escaneados', 'Faltantes', 'No esperados',
        ], $contexto);
    }

    /**
     * Excel / PDF del resumen de una ronda. `?tipo=unidades` (por defecto) =
     * unidades identificadas, respetando el filtro de sección
     * (`todos`/`encontrados`/`faltantes`/`no_esperados`). `?tipo=cantidad` =
     * artículos por cantidad (esperado / contado / diferencia / resultado). Sin
     * `->paginate()`: exporta la sección COMPLETA. Mismo permiso que `show()`.
     */
    public function exportar(Request $request, InventarioFisico $inventarioFisico): BinaryFileResponse|HttpResponse
    {
        $this->authorize('view', $inventarioFisico);

        $inventarioFisico->loadMissing('empresa:id,nombre_comercial,logo_ruta');

        if ($request->input('tipo') === 'cantidad') {
            return $this->exportarCantidad($request, $inventarioFisico);
        }

        $seccion = $request->input('seccion');
        $seccion = in_array($seccion, ServicioResumenInventarioFisico::SECCIONES, true) ? $seccion : 'todos';

        $filas = $this->resumen->consultaSeccion($inventarioFisico, $seccion)->get()
            ->map(function (InventarioFisicoUnidad $f): array {
                $d = $this->resumen->filaResumen($f);

                return [
                    $d['clasificacion_etiqueta'],
                    $d['codigo'],
                    $d['activo'],
                    $d['marca_modelo'],
                    $d['almacen'],
                    $d['colaborador'],
                    $d['estado_visible_etiqueta'],
                    $d['escaneado_en'],
                    $d['escaneado_por'],
                ];
            })->all();

        $contexto = new ContextoExportacion(
            'Inventario físico '.$inventarioFisico->folio,
            $inventarioFisico->empresa,
            array_filter([
                'Ronda' => $inventarioFisico->nombre,
                'Sección' => 'Unidades identificadas · '.($seccion === 'todos' ? 'Todos' : ucfirst(str_replace('_', ' ', $seccion))),
            ]),
            count($filas),
        );

        return $this->respuestaExportacion($request->input('formato', 'xlsx'), $filas, [
            'Clasificación', 'Código', 'Activo', 'Marca / Modelo', 'Almacén', 'Asignada a', 'Estado actual', 'Escaneada en', 'Escaneada por',
        ], $contexto);
    }

    private function exportarCantidad(Request $request, InventarioFisico $inventarioFisico): BinaryFileResponse|HttpResponse
    {
        $etiquetaResultado = [
            InventarioFisicoExistencia::RESULTADO_PENDIENTE => 'Pendiente',
            InventarioFisicoExistencia::RESULTADO_COINCIDE => 'Coincide',
            InventarioFisicoExistencia::RESULTADO_FALTANTE => 'Faltante',
            InventarioFisicoExistencia::RESULTADO_SOBRANTE => 'Sobrante',
        ];

        $filas = $this->resumen->consultaExistencias($inventarioFisico)->get()
            ->map(function (InventarioFisicoExistencia $e) use ($etiquetaResultado): array {
                $d = $this->resumen->filaExistencia($e);

                return [
                    $d['activo'],
                    $d['talla'] ?? '—',
                    $d['cantidad_esperada'],
                    $d['cantidad_contada'] ?? 'Sin verificar',
                    $d['diferencia'] ?? '—',
                    $etiquetaResultado[$d['resultado']] ?? $d['resultado'],
                ];
            })->all();

        $contexto = new ContextoExportacion(
            'Inventario físico '.$inventarioFisico->folio,
            $inventarioFisico->empresa,
            array_filter([
                'Ronda' => $inventarioFisico->nombre,
                'Sección' => 'Artículos por cantidad',
            ]),
            count($filas),
        );

        return $this->respuestaExportacion($request->input('formato', 'xlsx'), $filas, [
            'Activo', 'Talla', 'Esperado', 'Contado', 'Diferencia', 'Resultado',
        ], $contexto);
    }

    /**
     * @return array<string, mixed>
     */
    private function filtrosListado(Request $request): array
    {
        return $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', Rule::enum(EstadoInventarioFisico::class)],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Builder<InventarioFisico>
     */
    private function consultaRondas(Request $request, array $filtros): Builder
    {
        $empresaFiltro = $this->empresaDelFiltro($request);
        $idsScope = $empresaFiltro !== null ? collect([$empresaFiltro->id]) : $this->idsEmpresasAutorizadas($request);

        return InventarioFisico::query()
            ->whereIn('empresa_id', $idsScope)
            ->with(['empresa:id,nombre_comercial', 'almacen:id,nombre', 'usuario:id,name'])
            ->when($filtros['buscar'] ?? null, fn (Builder $q, string $b) => $q->where(
                fn (Builder $s) => $s->where('nombre', 'like', "%{$b}%")->orWhere('folio', 'like', "%{$b}%")
            ))
            ->when($filtros['estado'] ?? null, fn (Builder $q, $v) => $q->where('estado', $v))
            ->orderByDesc('id');
    }

    private function seccionValida(Request $request): string
    {
        $seccion = $request->input('seccion');

        return in_array($seccion, ServicioResumenInventarioFisico::SECCIONES, true) ? $seccion : 'faltantes';
    }

    /**
     * Contadores de varias rondas en UNA consulta agregada (evita N+1 en el
     * listado): sum condicional por `inventario_fisico_id`.
     *
     * @param  Collection<int, int>|Arrayable<int, int>  $rondaIds
     * @return array<int, array<string, int>>
     */
    private function contadoresPorRonda(Collection|Arrayable $rondaIds): array
    {
        $ids = collect($rondaIds)->all();

        if ($ids === []) {
            return [];
        }

        return DB::table('inventario_fisico_unidades')
            ->whereIn('inventario_fisico_id', $ids)
            ->selectRaw('
                inventario_fisico_id,
                sum(case when esperada = 1 then 1 else 0 end) as esperados,
                sum(case when esperada = 1 and escaneado_en is not null then 1 else 0 end) as encontrados_esperados,
                sum(case when escaneado_en is not null then 1 else 0 end) as escaneados,
                sum(case when esperada = 0 then 1 else 0 end) as no_esperados
            ')
            ->groupBy('inventario_fisico_id')
            ->get()
            ->mapWithKeys(fn (object $r): array => [
                (int) $r->inventario_fisico_id => [
                    'esperados' => (int) $r->esperados,
                    'escaneados' => (int) $r->escaneados,
                    'faltantes' => (int) $r->esperados - (int) $r->encontrados_esperados,
                    'no_esperados' => (int) $r->no_esperados,
                ],
            ])
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function contadoresVacios(): array
    {
        return ['esperados' => 0, 'escaneados' => 0, 'faltantes' => 0, 'no_esperados' => 0];
    }
}
