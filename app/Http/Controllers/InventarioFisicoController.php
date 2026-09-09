<?php

namespace App\Http\Controllers;

use App\Acciones\CrearRondaInventarioFisico;
use App\Acciones\EscanearUnidadInventarioFisico;
use App\Acciones\FinalizarRondaInventarioFisico;
use App\Enums\EstadoInventarioFisico;
use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Controllers\Concerns\ExportaListado;
use App\Http\Requests\InventarioFisico\EscanearUnidadRequest;
use App\Http\Requests\InventarioFisico\GuardarInventarioFisicoRequest;
use App\Models\Almacen;
use App\Models\InventarioFisico;
use App\Models\InventarioFisicoUnidad;
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
            'iniciado_en' => $r->created_at?->toDateTimeString(),
            'finalizado_en' => $r->finalizado_en?->toDateTimeString(),
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

        return response()->json([
            'total' => CrearRondaInventarioFisico::universo($empresa->id, $almacenId)->count(),
        ]);
    }

    public function store(GuardarInventarioFisicoRequest $request, CrearRondaInventarioFisico $accion): RedirectResponse
    {
        $empresa = $request->empresaResuelta();

        $almacen = $request->filled('almacen_id')
            ? Almacen::query()->findOrFail($request->integer('almacen_id'))
            : null;

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

        $inventarioFisico->load(['empresa:id,nombre_comercial', 'almacen:id,nombre', 'usuario:id,name']);

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
                'iniciado_en' => $inventarioFisico->created_at?->toDateTimeString(),
                'finalizado_en' => $inventarioFisico->finalizado_en?->toDateTimeString(),
            ],
            'contadores' => $this->resumen->contadores($inventarioFisico),
            'seccion' => $seccion,
            'unidades' => $unidades,
            'permisos' => [
                'administrar' => $request->user()->can('administrar', $inventarioFisico),
            ],
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

    public function finalizar(Request $request, InventarioFisico $inventarioFisico, FinalizarRondaInventarioFisico $accion): RedirectResponse
    {
        $this->authorize('administrar', $inventarioFisico);

        $accion->ejecutar($inventarioFisico, $request->user()?->id);

        return back()->with('toast', ['type' => 'success', 'message' => 'Ronda de inventario físico finalizada.']);
    }

    /**
     * Excel / PDF del resumen de una ronda, respetando el mismo filtro de
     * sección que la pantalla. Mismo permiso que `show()`.
     */
    public function exportar(Request $request, InventarioFisico $inventarioFisico): BinaryFileResponse|HttpResponse
    {
        $this->authorize('view', $inventarioFisico);

        $seccion = $request->input('seccion');
        $seccionValida = in_array($seccion, ServicioResumenInventarioFisico::SECCIONES, true) ? $seccion : null;

        $consulta = $seccionValida !== null
            ? $this->resumen->consultaSeccion($inventarioFisico, $seccionValida)
            : InventarioFisicoUnidad::query()
                ->where('inventario_fisico_id', $inventarioFisico->id)
                ->with([
                    'unidad:id,codigo,activo_id,almacen_id,empresa_id,colaborador_id,estado,condicion',
                    'unidad.activo:id,nombre', 'unidad.almacen:id,nombre',
                    'unidad.colaborador:id,nombre_completo', 'escaneadoPor:id,name',
                ])
                ->orderByRaw('escaneado_en is null desc')
                ->orderBy('id');

        $clasificacionEtiqueta = [
            InventarioFisicoUnidad::CLASIFICACION_ENCONTRADO => 'Encontrado',
            InventarioFisicoUnidad::CLASIFICACION_FALTANTE => 'Faltante',
            InventarioFisicoUnidad::CLASIFICACION_NO_ESPERADO => 'No esperado',
        ];

        $filas = $consulta->get()->map(function (InventarioFisicoUnidad $f) use ($clasificacionEtiqueta): array {
            $d = $this->resumen->filaResumen($f);

            return [
                $clasificacionEtiqueta[$d['clasificacion']] ?? $d['clasificacion'],
                $d['codigo'],
                $d['activo'],
                $d['almacen'],
                $d['colaborador'],
                $d['estado_visible_etiqueta'],
                $d['escaneado_en'],
                $d['escaneado_por'],
            ];
        })->all();

        $inventarioFisico->loadMissing('empresa:id,nombre_comercial,logo_ruta');

        $contexto = new ContextoExportacion(
            'Inventario físico '.$inventarioFisico->folio,
            $inventarioFisico->empresa,
            array_filter([
                'Ronda' => $inventarioFisico->nombre,
                'Sección' => $seccionValida !== null ? ucfirst(str_replace('_', ' ', $seccionValida)) : 'Todas',
            ]),
            count($filas),
        );

        return $this->respuestaExportacion($request->input('formato', 'xlsx'), $filas, [
            'Clasificación', 'Código', 'Activo', 'Almacén', 'Asignada a', 'Estado actual', 'Escaneada en', 'Escaneada por',
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
