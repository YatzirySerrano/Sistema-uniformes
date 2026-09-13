<?php

namespace App\Http\Controllers;

use App\Acciones\ConfirmarAcuseDevolucion;
use App\Acciones\RegistrarDevolucionFirmada;
use App\Enums\CondicionDevolucion;
use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoDevolucion;
use App\Enums\EstadoUnidadActivo;
use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Controllers\Concerns\ExportaListado;
use App\Http\Requests\Devoluciones\GuardarDevolucionRequest;
use App\Models\Colaborador;
use App\Models\DetalleDevolucion;
use App\Models\Devolucion;
use App\Models\EntregaUniforme;
use App\Models\Evidencia;
use App\Servicios\ServicioCustodiaColaborador;
use App\Servicios\ServicioEvidencias;
use App\Soporte\ContextoExportacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Devoluciones de activos. SIEMPRE se originan desde una entrega concreta
 * (`?entrega_id=` precarga el formulario con sus renglones pendientes); el
 * almacén destino se elige explícitamente (por defecto el de origen de la
 * entrega, seleccionable si abastece la empresa y está activo).
 */
class DevolucionController extends Controller
{
    use ConEmpresa;
    use ExportaListado;

    public function __construct(private readonly ServicioCustodiaColaborador $custodia) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Devolucion::class);

        $empresaFiltro = $this->empresaDelFiltro($request);

        $devoluciones = $this->consultaDevoluciones($request)
            ->paginate($this->porPagina())
            ->withQueryString()
            ->through(fn (Devolucion $d): array => [
                'id' => $d->id,
                'folio' => $d->folio,
                'empresa' => $d->empresa?->nombre_comercial,
                'colaborador' => $d->colaborador?->nombre_completo,
                'entrega_folio' => $d->entrega?->folio,
                'sucursal' => $d->sucursal?->nombre,
                'registrada_por' => $d->registradaPor?->name,
                'fecha' => $d->fecha->toDateString(),
                'renglones' => $d->detalles_count,
                'estado' => $d->estado->value,
                'estado_etiqueta' => $d->estado->etiqueta(),
                'tiene_acuse' => (bool) $d->acuse_exists,
            ]);

        return Inertia::render('Devoluciones/Index', [
            'devoluciones' => $devoluciones,
            'filtros' => ['empresa_id' => $empresaFiltro?->id],
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
            'puedeCrear' => $request->user()->can('create', Devolucion::class),
        ]);
    }

    /**
     * Excel/PDF del listado, respetando el mismo filtro que `index()`.
     */
    public function exportar(Request $request): BinaryFileResponse|HttpResponse
    {
        $this->authorize('viewAny', Devolucion::class);

        $empresaFiltro = $this->empresaDelFiltro($request);
        $devoluciones = $this->consultaDevoluciones($request)->get();

        $filas = $devoluciones->map(fn (Devolucion $d): array => [
            $d->folio,
            $d->empresa?->nombre_comercial,
            $d->colaborador?->nombre_completo,
            $d->entrega?->folio,
            $d->sucursal?->nombre,
            $d->registradaPor?->name,
            $d->fecha->format('d/m/Y'),
            (int) $d->detalles_count,
        ])->all();

        $contexto = new ContextoExportacion('Devoluciones', $empresaFiltro, [], $devoluciones->count());

        return $this->respuestaExportacion($request->input('formato', 'xlsx'), $filas, [
            'Folio', 'Empresa', 'Colaborador', 'Entrega', 'Sucursal', 'Registró', 'Fecha', 'Renglones',
        ], $contexto);
    }

    /**
     * @return Builder<Devolucion>
     */
    private function consultaDevoluciones(Request $request): Builder
    {
        $idsAutorizadas = $this->idsEmpresasAutorizadas($request);
        $empresaFiltro = $this->empresaDelFiltro($request);

        return Devolucion::query()
            ->whereIn('empresa_id', $idsAutorizadas)
            ->when($empresaFiltro !== null, fn (Builder $q) => $q->where('empresa_id', $empresaFiltro->id))
            ->with(['colaborador:id,nombre_completo,numero_empleado', 'sucursal:id,nombre', 'empresa:id,nombre_comercial', 'entrega:id,folio', 'registradaPor:id,name'])
            ->withCount('detalles')
            ->withExists('acuse')
            ->latest();
    }

    /**
     * `?colaborador_id=` contextualiza la pantalla con los pendientes REALES
     * de ese colaborador (agrupados por entrega de origen), típicamente
     * llegando desde "Transferir a otra empresa" — nunca hace falta que el
     * usuario memorice folios. `?entrega_id=` (con o sin colaborador_id)
     * precarga directamente una entrega concreta para procesarla.
     */
    public function create(Request $request): Response
    {
        $this->authorize('create', Devolucion::class);

        $entrega = null;
        $entregaId = $request->integer('entrega_id');

        if ($entregaId > 0) {
            $candidata = EntregaUniforme::query()->with(['colaborador:id,nombre_completo,numero_empleado', 'empresa:id,nombre_comercial', 'almacen:id,nombre', 'detalles.talla:id,valor', 'detalles.unidadActivo:id,codigo,estado,condicion'])->find($entregaId);

            if ($candidata !== null && $request->user()->puedeAccederEmpresa($candidata->empresa_id)) {
                $entrega = $candidata;
            }
        }

        $colaboradorContexto = null;
        $colaboradorId = $request->integer('colaborador_id');

        if ($colaboradorId > 0) {
            $colaborador = Colaborador::find($colaboradorId);

            if ($colaborador !== null && $request->user()->puedeAccederEmpresa($colaborador->empresa_id)) {
                $colaboradorContexto = [
                    'id' => $colaborador->id,
                    'nombre' => $colaborador->nombre_completo,
                    'pendientes' => $this->custodia->pendientes($colaborador),
                ];
            }
        }

        return Inertia::render('Devoluciones/Crear', [
            'entrega' => $entrega === null ? null : $this->presentarEntrega($entrega),
            'colaboradorContexto' => $colaboradorContexto,
            'condiciones' => collect(CondicionDevolucion::cases())->map(fn ($c): array => ['valor' => $c->value, 'etiqueta' => $c->etiqueta()]),
            'condicionesUnidad' => collect(CondicionUnidadActivo::cases())->filter(fn ($c) => ! $c->esIncidencia())->values()
                ->map(fn ($c): array => ['valor' => $c->value, 'etiqueta' => $c->etiqueta()]),
            'textoConsentimiento' => ConfirmarAcuseDevolucion::TEXTO_CONSENTIMIENTO,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentarEntrega(EntregaUniforme $entrega): array
    {
        $pendientePorDetalle = $this->custodia->pendientesPorDetalle(
            $entrega->detalles->whereNull('unidad_activo_id')
        );

        return [
            'id' => $entrega->id,
            'folio' => $entrega->folio,
            'empresa_id' => $entrega->empresa_id,
            'empresa' => $entrega->empresa?->nombre_comercial,
            'colaborador' => $entrega->colaborador?->nombre_completo,
            'almacen_id' => $entrega->almacen_id,
            'almacen' => $entrega->almacen?->nombre,
            // Sólo renglones con algo TODAVÍA pendiente: una vez que un
            // renglón por cantidad llega a 0, o la unidad ya no está
            // Asignada, deja de ofrecerse aquí (devoluciones parciales).
            'renglones' => $entrega->detalles
                ->filter(fn ($d) => $d->unidad_activo_id === null
                    ? ($pendientePorDetalle[$d->id] ?? 0) > 0
                    : $d->unidadActivo?->estado === EstadoUnidadActivo::Asignada)
                ->values()
                ->map(fn ($d): array => [
                    'detalle_entrega_id' => $d->id,
                    'activo' => $d->activo_nombre_snapshot,
                    'talla' => $d->talla_valor_snapshot,
                    'cantidad' => $d->cantidad,
                    'pendiente' => $d->unidad_activo_id === null ? $pendientePorDetalle[$d->id] ?? 0 : null,
                    'es_unidad' => $d->unidad_activo_id !== null,
                    'unidad_codigo' => $d->unidadActivo?->codigo,
                    'unidad_disponible' => $d->unidadActivo?->estado === EstadoUnidadActivo::Asignada,
                    'unidad_estado_visible' => $d->unidadActivo?->estadoVisible()->value,
                    'unidad_estado_visible_etiqueta' => $d->unidadActivo?->estadoVisible()->etiqueta(),
                ]),
        ];
    }

    public function store(GuardarDevolucionRequest $request, RegistrarDevolucionFirmada $accion, ServicioEvidencias $evidenciasSvc): RedirectResponse
    {
        $datos = $request->validated();

        $entrega = EntregaUniforme::findOrFail((int) $datos['entrega_uniforme_id']);
        abort_unless($request->user()->puedeAccederEmpresa($entrega->empresa_id), 403, 'No tienes acceso a la empresa de esa entrega.');

        // Evidencia fotográfica OPCIONAL por renglón devuelto: se guarda en
        // disco privado antes de la transacción; SÓLO este `catch` la limpia
        // (las firmas las gestiona `ConfirmarAcuseDevolucion`).
        $evidencias = [];
        $metasEvidencia = [];
        try {
            foreach (array_keys($datos['activos'] ?? []) as $i) {
                $archivo = $request->file("activos.{$i}.evidencia");
                if ($archivo !== null) {
                    $meta = $evidenciasSvc->guardarPendiente($archivo, "evidencias/devoluciones/{$entrega->empresa_id}", $datos['activos'][$i]['evidencia_origen'] ?? 'archivo');
                    $evidencias["activo:{$i}"] = $meta;
                    $metasEvidencia[] = $meta;
                }
            }
            foreach (array_keys($datos['unidades'] ?? []) as $i) {
                $archivo = $request->file("unidades.{$i}.evidencia");
                if ($archivo !== null) {
                    $meta = $evidenciasSvc->guardarPendiente($archivo, "evidencias/devoluciones/{$entrega->empresa_id}", $datos['unidades'][$i]['evidencia_origen'] ?? 'archivo');
                    $evidencias["unidad:{$i}"] = $meta;
                    $metasEvidencia[] = $meta;
                }
            }

            $acuse = $accion->ejecutar(
                (int) $datos['entrega_uniforme_id'],
                (int) $datos['almacen_id'],
                $datos['fecha'],
                $datos['activos'] ?? [],
                $datos['unidades'] ?? [],
                $request->user()->id,
                $datos['motivo'] ?? null,
                $datos['notas'] ?? null,
                $datos['firma'],
                $datos['firma_operador'],
                true, // aceptación (validada por la regla `accepted`)
                $request->ip(),
                $request->userAgent(),
                $evidencias,
            );
        } catch (Throwable $e) {
            $evidenciasSvc->descartar($metasEvidencia);

            throw $e;
        }

        $acuse->loadMissing('devolucion:id,folio');

        // Continuidad del flujo Transferencia → Devoluciones: el frontend
        // manda `colaborador_id` sólo quien llegó desde ese contexto; el
        // backend revalida que sea realmente el dueño de la entrega antes de
        // usarlo (nunca se confía el ID recibido para decidir a quién
        // pertenece el resto de la custodia).
        $colaboradorId = $datos['colaborador_id'] ?? null;
        if ($colaboradorId !== null && (int) $colaboradorId === $entrega->colaborador_id) {
            $colaborador = $entrega->colaborador;

            if ($colaborador !== null && $this->custodia->tienePendientes($colaborador)) {
                return to_route('devoluciones.create', ['colaborador_id' => $colaborador->id])->with('toast', [
                    'type' => 'success',
                    'message' => "Devolución {$acuse->devolucion?->folio} confirmada. Quedan pendientes por procesar para completar la transferencia de {$colaborador->nombre_completo}.",
                ]);
            }

            if ($colaborador !== null) {
                return to_route('colaboradores.show', $colaborador->id)->with('toast', [
                    'type' => 'success',
                    'message' => "Devolución {$acuse->devolucion?->folio} confirmada. Ya no quedan pendientes: puedes completar la transferencia de {$colaborador->nombre_completo}.",
                ]);
            }
        }

        return to_route('devoluciones.show', $acuse->devolucion_id)->with('toast', [
            'type' => 'success', 'message' => "Devolución {$acuse->devolucion?->folio} confirmada correctamente.",
        ]);
    }

    /**
     * Detalle de una devolución (conceptualmente análogo a Entregas → Detalle):
     * datos, renglones con condición y evidencia, y el acuse si ya está firmado.
     */
    public function show(Request $request, Devolucion $devolucion): Response
    {
        $this->authorize('view', $devolucion);

        $devolucion->load([
            'detalles.activo:id,nombre',
            'detalles.talla:id,valor',
            'detalles.unidadActivo:id,codigo,public_token,estado,condicion',
            'detalles.evidencias:id,evidenciable_id,evidenciable_type,mime,origen',
            'colaborador:id,nombre_completo,numero_empleado',
            'sucursal:id,nombre',
            'empresa:id,nombre_comercial',
            'almacen:id,nombre',
            'entrega:id,folio',
            'registradaPor:id,name',
            'acuse',
        ]);

        return Inertia::render('Devoluciones/Detalle', [
            'devolucion' => [
                'id' => $devolucion->id,
                'folio' => $devolucion->folio,
                'estado' => $devolucion->estado->value,
                'estado_etiqueta' => $devolucion->estado->etiqueta(),
                'empresa' => $devolucion->empresa?->nombre_comercial,
                'sucursal' => $devolucion->sucursal?->nombre,
                'colaborador' => $devolucion->colaborador?->nombre_completo,
                'numero_empleado' => $devolucion->colaborador?->numero_empleado,
                'entrega_id' => $devolucion->entrega_uniforme_id,
                'entrega_folio' => $devolucion->entrega?->folio,
                'almacen' => $devolucion->almacen?->nombre,
                'fecha' => $devolucion->fecha->toDateString(),
                'motivo' => $devolucion->motivo,
                'notas' => $devolucion->notas,
                'registrada_por' => $devolucion->registradaPor?->name,
                'registrada_en' => $devolucion->created_at?->toIso8601String(),
                'confirmada_en' => $devolucion->confirmada_en?->toIso8601String(),
                'items' => $devolucion->detalles->map(fn (DetalleDevolucion $d): array => [
                    'activo' => $d->activo?->nombre,
                    'talla' => $d->talla?->valor,
                    'cantidad' => $d->cantidad,
                    'condicion' => $d->unidad_activo_id !== null
                        ? $d->condicion_unidad?->etiqueta()
                        : $d->condicion->etiqueta(),
                    'unidad_codigo' => $d->unidadActivo?->codigo,
                    'reingresa_inventario' => $d->reingresa_inventario,
                    'evidencias' => $d->evidencias->map(fn (Evidencia $e): array => [
                        'url' => route('devoluciones.evidencias.ver', $e),
                        'mime' => $e->mime,
                    ])->all(),
                ]),
            ],
            'acuse' => $devolucion->acuse === null ? null : [
                'id' => $devolucion->acuse->id,
                'folio' => $devolucion->acuse->folio,
                'firmado_en' => $devolucion->acuse->firmado_en->toIso8601String(),
                'tiene_pdf' => $devolucion->acuse->tienePdf(),
                'ver_pdf' => $request->user()->can('verPdf', $devolucion->acuse),
                'ver_firma' => $request->user()->can('verFirma', $devolucion->acuse),
            ],
            'permisos' => [
                'firmar' => $devolucion->estado === EstadoDevolucion::PendienteFirma
                    && $request->user()->can('confirmar', $devolucion),
            ],
        ]);
    }

    /**
     * Sirve, en streaming, la imagen de evidencia de un renglón de devolución.
     * Autorizada contra la DEVOLUCIÓN dueña del renglón (anti-IDOR).
     */
    public function verEvidencia(Request $request, Evidencia $evidencia): StreamedResponse
    {
        abort_unless($evidencia->evidenciable_type === DetalleDevolucion::class, 404);

        $detalle = DetalleDevolucion::query()->with('devolucion')->find($evidencia->evidenciable_id);
        abort_if($detalle === null || $detalle->devolucion === null, 404);

        $this->authorize('view', $detalle->devolucion);
        abort_unless(Storage::disk($evidencia->disco)->exists($evidencia->ruta), 404);

        return Storage::disk($evidencia->disco)->response($evidencia->ruta, 'evidencia.'.$evidencia->extension, [
            'Content-Type' => $evidencia->mime,
            'Content-Disposition' => 'inline; filename="evidencia.'.$evidencia->extension.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
