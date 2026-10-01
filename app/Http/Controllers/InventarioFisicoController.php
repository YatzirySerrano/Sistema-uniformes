<?php

namespace App\Http\Controllers;

use App\Acciones\AplicarCorreccionesInventarioFisico;
use App\Acciones\CrearRondaInventarioFisico;
use App\Acciones\DesmarcarUnidadPresente;
use App\Acciones\EscanearUnidadInventarioFisico;
use App\Acciones\FinalizarRondaInventarioFisico;
use App\Acciones\MarcarUnidadPresente;
use App\Acciones\VerificarExistenciaInventarioFisico;
use App\Enums\EstadoInventarioFisico;
use App\Enums\EstadoVisibleUnidad;
use App\Excepciones\VerificacionInventarioFisicoConcurrenteException;
use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Controllers\Concerns\ExportaListado;
use App\Http\Requests\InventarioFisico\AplicarCorreccionesRequest;
use App\Http\Requests\InventarioFisico\EscanearUnidadRequest;
use App\Http\Requests\InventarioFisico\FinalizarRondaRequest;
use App\Http\Requests\InventarioFisico\GuardarInventarioFisicoRequest;
use App\Http\Requests\InventarioFisico\ResolverExistenciaNoVerificableRequest;
use App\Http\Requests\InventarioFisico\VerificarExistenciaRequest;
use App\Models\InventarioFisico;
use App\Models\InventarioFisicoExistencia;
use App\Models\InventarioFisicoUnidad;
use App\Servicios\ServicioCustodiaColaborador;
use App\Servicios\ServicioResumenInventarioFisico;
use App\Soporte\ContextoExportacion;
use App\Soporte\FechaHora;
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
use Spatie\LaravelPdf\Enums\Format;
use Spatie\LaravelPdf\Facades\Pdf;
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
            return response()->json(['total' => 0, 'existencias' => 0, 'almacenes' => 0, 'custodias' => 0]);
        }

        // Mismas definiciones exactas que el snapshot (tres conteos, sin
        // traer filas).
        $existencias = CrearRondaInventarioFisico::universoExistencias($empresa->id);

        return response()->json([
            'total' => CrearRondaInventarioFisico::universo($empresa->id)->count(),
            'existencias' => (clone $existencias)->count(),
            'almacenes' => (clone $existencias)->distinct()->count('almacen_id'),
            // Bolsas de custodia por cantidad (custodio + activo + variante + finalidad).
            'custodias' => count(app(ServicioCustodiaColaborador::class)->bolsasCantidadDeEmpresa($empresa->id)),
        ]);
    }

    public function store(GuardarInventarioFisicoRequest $request, CrearRondaInventarioFisico $accion): RedirectResponse
    {
        $empresa = $request->empresaResuelta();

        // Ronda INTEGRAL de la empresa: el universo lo construye el backend.
        $ronda = $accion->ejecutar(
            $empresa,
            $request->string('nombre')->toString(),
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
        $estadoUnidad = $this->estadoUnidadValido($request);
        $almacenes = $this->resumen->almacenesDeLaRonda($inventarioFisico);
        $almacenFiltro = $this->almacenValido($request, $almacenes);
        $rondaAbierta = $inventarioFisico->estaEnProceso();

        $unidades = $this->resumen->consultaSeccion($inventarioFisico, $seccion, $estadoUnidad, $almacenFiltro)
            ->paginate($this->porPagina(), ['*'], 'pagina')
            ->withQueryString()
            ->through(fn (InventarioFisicoUnidad $f): array => $this->resumen->filaResumen($f, $rondaAbierta));

        $inventarioFisico->load(['empresa:id,nombre_comercial', 'almacen:id,nombre', 'usuario:id,name', 'firma:id,inventario_fisico_id,nombre_firmante,hash_firma,aceptado_en', 'correccionesAplicadasPor:id,name']);

        $contadores = $this->resumen->contadores($inventarioFisico);

        // Los renglones de existencias por cantidad de una ronda son pocos (uno
        // por activo+variante del almacén): se sirven completos, sin paginar.
        $filasExistencia = $this->resumen->consultaExistencias($inventarioFisico, 'todos', $almacenFiltro)->get();
        $motivos = $this->resumen->motivosNoVerificables($filasExistencia);
        $existencias = $filasExistencia
            ->map(fn (InventarioFisicoExistencia $e): array => $this->resumen->filaExistencia($e, $motivos))
            ->values();

        $correcciones = $this->resumen->resumenCorrecciones($inventarioFisico, $contadores);

        return Inertia::render('InventarioFisico/Detalle', [
            'ronda' => [
                'id' => $inventarioFisico->id,
                'folio' => $inventarioFisico->folio,
                'nombre' => $inventarioFisico->nombre,
                'estado' => $inventarioFisico->estado->value,
                'estado_etiqueta' => $inventarioFisico->estado->etiqueta(),
                'empresa' => $inventarioFisico->empresa?->nombre_comercial,
                'almacen' => $inventarioFisico->almacen?->nombre,
                // Sin almacén = ronda INTEGRAL de la empresa (todos sus
                // almacenes + unidades identificadas).
                'general' => $inventarioFisico->almacen_id === null,
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
            'estadoUnidad' => $estadoUnidad?->value,
            'almacenFiltro' => $almacenFiltro,
            'almacenes' => $almacenes,
            'estadosUnidad' => array_map(fn (EstadoVisibleUnidad $e): array => ['valor' => $e->value, 'etiqueta' => $e->etiqueta()], ServicioResumenInventarioFisico::ESTADOS_FILTRABLES),
            'unidades' => $unidades,
            'existencias' => $existencias,
            'textoAceptacion' => FinalizarRondaInventarioFisico::TEXTO_ACEPTACION,
            'correcciones' => $correcciones,
            // Combinaciones que impidieron aplicar el lote en el último
            // intento (si lo hubo) — se consume una sola vez, igual que
            // `flash.toast`.
            'conflictosInventarioFisico' => session()->pull('conflictosInventarioFisico'),
            'permisos' => [
                'administrar' => $request->user()->can('administrar', $inventarioFisico),
                'finalizar' => $request->user()->can('administrar', $inventarioFisico)
                    && $inventarioFisico->estaEnProceso()
                    && $contadores['cantidad_pendientes'] === 0,
                'aplicarCorrecciones' => $request->user()->can('aplicarCorrecciones', $inventarioFisico)
                    && $inventarioFisico->estaFinalizada()
                    && $inventarioFisico->firma !== null
                    && $correcciones['estado'] === 'pendientes',
            ],
        ]);
    }

    /**
     * Aplica en UN SOLO lote, todo-o-nada, las diferencias verificadas de una
     * ronda ya finalizada y firmada. Ver `App\Acciones\AplicarCorreccionesInventarioFisico`
     * para las reglas de negocio (nunca se confía en lo que crea el frontend).
     */
    public function aplicarCorrecciones(
        AplicarCorreccionesRequest $request,
        InventarioFisico $inventarioFisico,
        AplicarCorreccionesInventarioFisico $accion,
    ): RedirectResponse {
        $accion->ejecutar($inventarioFisico, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Correcciones de inventario aplicadas.']);
    }

    public function verificarExistencia(
        VerificarExistenciaRequest $request,
        InventarioFisico $inventarioFisico,
        InventarioFisicoExistencia $existencia,
        VerificarExistenciaInventarioFisico $accion,
    ): JsonResponse {
        try {
            $fila = $accion->ejecutar(
                $inventarioFisico,
                $existencia,
                (int) $request->integer('cantidad_contada'),
                $request->user(),
                $request->filled('verificada_en_vista') ? $request->string('verificada_en_vista')->toString() : null,
            );
        } catch (VerificacionInventarioFisicoConcurrenteException $e) {
            return $this->respuestaConflicto($inventarioFisico, $e);
        }

        return $this->respuestaFilaExistencia($inventarioFisico, $fila);
    }

    /**
     * «No fue posible verificar» (motivo opcional): resuelve el renglón para
     * el cierre SIN cantidad contada — nunca es un 0 ni una diferencia.
     */
    public function marcarExistenciaNoVerificable(
        ResolverExistenciaNoVerificableRequest $request,
        InventarioFisico $inventarioFisico,
        InventarioFisicoExistencia $existencia,
        VerificarExistenciaInventarioFisico $accion,
    ): JsonResponse {
        try {
            $fila = $accion->marcarNoVerificable(
                $inventarioFisico,
                $existencia,
                $request->input('motivo'),
                $request->user(),
                $request->filled('verificada_en_vista') ? $request->string('verificada_en_vista')->toString() : null,
            );
        } catch (VerificacionInventarioFisicoConcurrenteException $e) {
            return $this->respuestaConflicto($inventarioFisico, $e);
        }

        return $this->respuestaFilaExistencia($inventarioFisico, $fila);
    }

    /**
     * Reabre a Pendiente un renglón marcado como «No fue posible verificar».
     */
    public function reabrirExistencia(
        ResolverExistenciaNoVerificableRequest $request,
        InventarioFisico $inventarioFisico,
        InventarioFisicoExistencia $existencia,
        VerificarExistenciaInventarioFisico $accion,
    ): JsonResponse {
        try {
            $fila = $accion->reabrir(
                $inventarioFisico,
                $existencia,
                $request->user(),
                $request->filled('verificada_en_vista') ? $request->string('verificada_en_vista')->toString() : null,
            );
        } catch (VerificacionInventarioFisicoConcurrenteException $e) {
            return $this->respuestaConflicto($inventarioFisico, $e);
        }

        return $this->respuestaFilaExistencia($inventarioFisico, $fila);
    }

    private function respuestaFilaExistencia(InventarioFisico $ronda, InventarioFisicoExistencia $fila): JsonResponse
    {
        return response()->json([
            'existencia' => $this->resumen->filaExistencia(
                $fila->loadMissing(ServicioResumenInventarioFisico::RELACIONES_EXISTENCIA),
                $this->resumen->motivosNoVerificables([$fila]),
            ),
            'contadores' => $this->resumen->contadores($ronda),
        ]);
    }

    public function marcarUnidadPresente(
        Request $request,
        InventarioFisico $inventarioFisico,
        InventarioFisicoUnidad $unidad,
        MarcarUnidadPresente $accion,
    ): JsonResponse {
        abort_unless($request->user()->can('administrar', $inventarioFisico), 403);

        try {
            $fila = $accion->ejecutar($inventarioFisico, $unidad, $request->user());
        } catch (VerificacionInventarioFisicoConcurrenteException $e) {
            return $this->respuestaConflicto($inventarioFisico, $e);
        }

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

        try {
            $fila = $accion->ejecutar(
                $inventarioFisico,
                $unidad,
                $request->user(),
                $request->filled('escaneado_en_vista') ? (string) $request->input('escaneado_en_vista') : null,
            );
        } catch (VerificacionInventarioFisicoConcurrenteException $e) {
            return $this->respuestaConflicto($inventarioFisico, $e);
        }

        return $this->respuestaFilaUnidad($inventarioFisico, $fila);
    }

    /**
     * Respuesta JSON común de marcar / desmarcar: la fila actualizada (para que
     * el frontend refleje el estado real sin recargar) + los contadores
     * derivados del servidor (nunca un `++` a ciegas en el cliente).
     */
    private function respuestaFilaUnidad(InventarioFisico $ronda, InventarioFisicoUnidad $fila): JsonResponse
    {
        $fila->loadMissing(ServicioResumenInventarioFisico::RELACIONES_FILA);

        return response()->json([
            // Marcar / desmarcar sólo ocurre con la ronda abierta.
            'unidad' => $this->resumen->filaResumen($fila, true),
            'contadores' => $this->resumen->contadores($ronda),
        ]);
    }

    /**
     * 409 amigable cuando otro encargado ya verificó/cambió el renglón: el
     * mensaje dice quién y cuándo, y viaja la fila REAL (unidad o existencia)
     * con los contadores para que la pantalla se refresque sin recargar.
     */
    private function respuestaConflicto(InventarioFisico $ronda, VerificacionInventarioFisicoConcurrenteException $e): JsonResponse
    {
        $fila = $e->fila->fresh();

        $cuerpo = ['message' => $e->getMessage(), 'contadores' => $this->resumen->contadores($ronda)];

        if ($fila instanceof InventarioFisicoUnidad) {
            $cuerpo['unidad'] = $this->resumen->filaResumen($fila->load(ServicioResumenInventarioFisico::RELACIONES_FILA), true);
        } elseif ($fila instanceof InventarioFisicoExistencia) {
            $cuerpo['existencia'] = $this->resumen->filaExistencia(
                $fila->load(ServicioResumenInventarioFisico::RELACIONES_EXISTENCIA),
                $this->resumen->motivosNoVerificables([$fila]),
            );
        }

        return response()->json($cuerpo, 409);
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
                $r->almacen->nombre ?? 'Toda la empresa',
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
            generadoPor: $request->user()?->name,
        );

        return $this->respuestaExportacion($request->input('formato', 'xlsx'), $filas, [
            'Folio', 'Nombre', 'Empresa', 'Alcance', 'Inicio', 'Iniciada por', 'Estado',
            'Esperados', 'Escaneados', 'Faltantes', 'No esperados',
        ], $contexto);
    }

    /**
     * Exportación de una ronda. Mismo permiso que `show()` (Policy `view`:
     * `inventario-fisico.ver` + acceso a la empresa de la ronda).
     *
     * - PDF (`?formato=pdf`): el ACTA completa de la ronda — cabecera,
     *   contadores, TODAS las unidades y TODOS los renglones por cantidad,
     *   firma y correcciones (`acta()`). Antes el PDF era la tabla genérica
     *   de UNA sección de unidades (la del detalle, por defecto
     *   `faltantes`) e ignoraba las existencias por cantidad, así que una
     *   ronda sólo por cantidad, o sin faltantes, salía vacía.
     * - Excel: una hoja plana por tipo. `?tipo=unidades` (por defecto) =
     *   unidades identificadas, respetando la sección
     *   (`todos`/`encontrados`/`faltantes`/`no_esperados`); `?tipo=cantidad`
     *   = artículos por cantidad. Sin `->paginate()`: exporta completo.
     */
    public function exportar(Request $request, InventarioFisico $inventarioFisico): BinaryFileResponse|HttpResponse
    {
        $this->authorize('view', $inventarioFisico);

        $inventarioFisico->loadMissing('empresa:id,nombre_comercial,logo_ruta');

        if ($request->input('formato') === 'pdf') {
            return $this->acta($request, $inventarioFisico);
        }

        if ($request->input('tipo') === 'cantidad') {
            return $this->exportarCantidad($request, $inventarioFisico);
        }

        $seccion = $request->input('seccion');
        $seccion = in_array($seccion, ServicioResumenInventarioFisico::SECCIONES, true) ? $seccion : 'todos';

        $rondaAbierta = $inventarioFisico->estaEnProceso();
        $almacenExport = $request->filled('almacen_id') ? $request->integer('almacen_id') : null;
        $filas = $this->resumen->consultaSeccion($inventarioFisico, $seccion, $this->estadoUnidadValido($request), $almacenExport)->get()
            ->map(function (InventarioFisicoUnidad $f) use ($rondaAbierta): array {
                $d = $this->resumen->filaResumen($f, $rondaAbierta);

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
            generadoPor: $request->user()?->name,
        );

        return $this->respuestaExportacion($request->input('formato', 'xlsx'), $filas, [
            'Clasificación', 'Código', 'Activo', 'Marca / Modelo', 'Almacén', 'Asignada a', 'Estado actual', 'Escaneada en', 'Escaneada por',
        ], $contexto);
    }

    /**
     * Acta PDF de la ronda (Browsershot, identidad visual compartida de los
     * reportes). Los datos salen íntegramente de `datosActa()`: snapshot y
     * registros propios de la ronda, nunca el estado actual del inventario.
     */
    private function acta(Request $request, InventarioFisico $inventarioFisico): HttpResponse
    {
        $datos = $this->resumen->datosActa($inventarioFisico);

        $contexto = new ContextoExportacion(
            'Inventario físico '.$inventarioFisico->folio,
            $inventarioFisico->empresa,
            [],
            count($datos['unidades']) + count($datos['existencias']),
            generadoPor: $request->user()?->name,
        );

        $pdf = Pdf::view('reportes.inventario-fisico-acta', [
            'contexto' => $contexto,
            ...$datos,
        ])
            ->format(Format::Letter)
            ->portrait()
            ->margins(10, 10, 16, 10)
            ->footerView('reportes._pie');

        return response($pdf->generatePdfContent(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$contexto->nombreArchivo().'.pdf"',
        ]);
    }

    private function exportarCantidad(Request $request, InventarioFisico $inventarioFisico): BinaryFileResponse|HttpResponse
    {
        $almacenExport = $request->filled('almacen_id') ? $request->integer('almacen_id') : null;
        $filasExistencia = $this->resumen->consultaExistencias($inventarioFisico, 'todos', $almacenExport)->get();
        $motivos = $this->resumen->motivosNoVerificables($filasExistencia);
        $filas = $filasExistencia
            ->map(function (InventarioFisicoExistencia $e) use ($motivos): array {
                $d = $this->resumen->filaExistencia($e, $motivos);

                return [
                    $e->esCustodia() ? 'Bajo custodia' : 'En almacén',
                    $this->resumen->ubicacionExistencia($e),
                    $d['finalidad_etiqueta'] ?? 'No aplica',
                    $d['activo'],
                    $d['talla'] ?? 'Sin variante',
                    $d['cantidad_esperada'],
                    $d['no_verificable'] ? '—' : ($d['cantidad_contada'] ?? 'Sin verificar'),
                    $d['diferencia'] ?? '—',
                    ServicioResumenInventarioFisico::RESULTADO_ETIQUETA[$d['resultado']] ?? $d['resultado'],
                    $d['verificada_por'] ?? '—',
                    $e->verificada_en !== null ? FechaHora::local($e->verificada_en) : '—',
                    $d['no_verificable'] ? ($d['motivo_no_verificable'] ?? '—') : '—',
                ];
            })->all();

        $contexto = new ContextoExportacion(
            'Inventario físico '.$inventarioFisico->folio,
            $inventarioFisico->empresa,
            array_filter([
                'Ronda' => $inventarioFisico->nombre,
                'Sección' => 'Artículos por cantidad (almacén y custodia)',
            ]),
            count($filas),
            generadoPor: $request->user()?->name,
        );

        return $this->respuestaExportacion($request->input('formato', 'xlsx'), $filas, [
            'Origen', 'Almacén / custodio', 'Finalidad', 'Activo', 'Talla / variante', 'Cantidad esperada', 'Cantidad contada', 'Diferencia', 'Resultado', 'Verificado por', 'Verificado en', 'Motivo (no verificable)',
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

    /**
     * Filtro por estado OPERATIVO actual de la unidad (sólo los estados que
     * una ronda puede esperar); cualquier otro valor se ignora.
     */
    private function estadoUnidadValido(Request $request): ?EstadoVisibleUnidad
    {
        $estado = EstadoVisibleUnidad::tryFrom((string) $request->input('estado_unidad', ''));

        return in_array($estado, ServicioResumenInventarioFisico::ESTADOS_FILTRABLES, true) ? $estado : null;
    }

    /**
     * Filtro por almacén: sólo uno de los que aparecen en la ronda.
     *
     * @param  list<array{id: int, nombre: string}>  $almacenes
     */
    private function almacenValido(Request $request, array $almacenes): ?int
    {
        $id = $request->integer('almacen_id');

        return in_array($id, array_column($almacenes, 'id'), true) ? $id : null;
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
