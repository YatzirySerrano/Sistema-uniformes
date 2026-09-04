<?php

namespace App\Http\Controllers;

use App\Acciones\DarDeBajaUnidadActivo;
use App\Acciones\MarcarUnidadIncidencia;
use App\Acciones\RecuperarUnidadActivo;
use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoUnidadActivo;
use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Controllers\Concerns\ExportaListado;
use App\Http\Requests\Activos\DarDeBajaUnidadRequest;
use App\Http\Requests\Activos\MarcarIncidenciaUnidadRequest;
use App\Http\Requests\Activos\RecuperarUnidadRequest;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\MovimientoInventario;
use App\Models\UnidadActivo;
use App\Servicios\ServicioEtiquetasQr;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Unidades de seguimiento individual. Vive dentro del hub de Activos (no es
 * un módulo de navegación aparte). El detalle público (QR) se resuelve por
 * `public_token`, nunca por id incremental (evita IDOR/enumeración); toda la
 * app vive detrás de auth, así que no hace falta una vista pública separada.
 */
class UnidadActivoController extends Controller
{
    use ConEmpresa;
    use ExportaListado;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', UnidadActivo::class);

        $empresaFiltro = $this->empresaDelFiltro($request);
        $filtros = $this->filtrosListado($request);

        $unidades = $this->consultaUnidades($request, $filtros)
            ->paginate($this->porPagina())
            ->withQueryString()
            ->through(fn (UnidadActivo $u): array => [
                'id' => $u->id,
                'public_token' => $u->public_token,
                'codigo' => $u->codigo,
                'activo' => $u->activo?->nombre,
                'almacen' => $u->almacen?->nombre,
                'colaborador' => $u->colaborador?->nombre_completo,
                'estado' => $u->estado->value,
                'estado_etiqueta' => $u->estado->etiqueta(),
                'condicion' => $u->condicion->value,
                'condicion_etiqueta' => $u->condicion->etiqueta(),
                'entregable' => $u->esEntregable(),
            ]);

        return Inertia::render('Activos/Unidades', [
            'unidades' => $unidades,
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
            'filtros' => [
                'buscar' => $filtros['buscar'] ?? '',
                'empresa_id' => $empresaFiltro?->id,
                'activo_id' => $filtros['activo_id'] ?? '',
                'almacen_id' => $filtros['almacen_id'] ?? '',
                'estado' => $filtros['estado'] ?? '',
                'condicion' => $filtros['condicion'] ?? '',
            ],
            'permisos' => [
                'administrar' => $request->user()->can('unidades-activo.administrar'),
            ],
        ]);
    }

    /**
     * Excel/PDF del listado, respetando los mismos filtros que `index()`.
     */
    public function exportar(Request $request): BinaryFileResponse|SymfonyResponse
    {
        $this->authorize('viewAny', UnidadActivo::class);

        $filtros = $this->filtrosListado($request);
        $unidades = $this->consultaUnidades($request, $filtros)->get();

        $filas = $unidades->map(fn (UnidadActivo $u): array => [
            $u->codigo,
            $u->activo?->nombre,
            $u->almacen?->nombre,
            $u->colaborador?->nombre_completo,
            $u->estado->etiqueta(),
            $u->condicion->etiqueta(),
        ])->all();

        return $this->respuestaExportacion($request->input('formato', 'xlsx'), $filas, [
            'Código', 'Activo', 'Almacén', 'Colaborador', 'Estado', 'Condición',
        ], 'Unidades identificadas');
    }

    /**
     * @return array<string, mixed>
     */
    private function filtrosListado(Request $request): array
    {
        return $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'activo_id' => ['nullable', 'integer'],
            'almacen_id' => ['nullable', 'integer'],
            'estado' => ['nullable', Rule::enum(EstadoUnidadActivo::class)],
            'condicion' => ['nullable', Rule::enum(CondicionUnidadActivo::class)],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Builder<UnidadActivo>
     */
    private function consultaUnidades(Request $request, array $filtros): Builder
    {
        $idsAutorizadas = $this->idsEmpresasAutorizadas($request);
        $empresaFiltro = $this->empresaDelFiltro($request);
        $idsScope = $empresaFiltro !== null ? collect([$empresaFiltro->id]) : $idsAutorizadas;

        return UnidadActivo::query()
            ->whereIn('empresa_id', $idsScope)
            ->with(['activo:id,nombre,codigo', 'almacen:id,nombre', 'colaborador:id,nombre_completo'])
            ->when($filtros['buscar'] ?? null, function (Builder $q, string $buscar): void {
                $q->where(function (Builder $sub) use ($buscar): void {
                    $sub->where('codigo', 'like', "%{$buscar}%")
                        ->orWhereHas('activo', fn (Builder $a) => $a->where('nombre', 'like', "%{$buscar}%"));
                });
            })
            ->when($filtros['activo_id'] ?? null, fn (Builder $q, $v) => $q->where('activo_id', $v))
            ->when($filtros['almacen_id'] ?? null, fn (Builder $q, $v) => $q->where('almacen_id', $v))
            ->when($filtros['estado'] ?? null, fn (Builder $q, $v) => $q->where('estado', $v))
            ->when($filtros['condicion'] ?? null, fn (Builder $q, $v) => $q->where('condicion', $v))
            ->orderByDesc('id');
    }

    public function show(Request $request, UnidadActivo $unidad): Response
    {
        $this->authorize('view', $unidad);

        $unidad->load(['activo:id,nombre,codigo,tipo_control', 'almacen:id,nombre', 'empresa:id,nombre_comercial', 'colaborador:id,nombre_completo', 'registradoPor:id,name']);

        $movimientos = MovimientoInventario::query()
            ->where('unidad_activo_id', $unidad->id)
            ->orderByDesc('ocurrido_en')
            ->get()
            ->map(fn (MovimientoInventario $m): array => [
                'tipo' => $m->tipo->etiqueta(),
                'motivo' => $m->motivo,
                'ocurrido_en' => $m->ocurrido_en->toDateTimeString(),
            ]);

        return Inertia::render('Activos/UnidadDetalle', [
            'unidad' => [
                'id' => $unidad->id,
                'public_token' => $unidad->public_token,
                'codigo' => $unidad->codigo,
                'estado' => $unidad->estado->value,
                'estado_etiqueta' => $unidad->estado->etiqueta(),
                'condicion' => $unidad->condicion->value,
                'condicion_etiqueta' => $unidad->condicion->etiqueta(),
                'observaciones' => $unidad->observaciones,
                'motivo_baja' => $unidad->motivo_baja,
                'dado_de_baja_en' => $unidad->dado_de_baja_en?->toDateTimeString(),
                'incidencia_motivo' => $unidad->incidencia_motivo,
                'incidencia_registrada_en' => $unidad->incidencia_registrada_en?->toDateTimeString(),
                'activo' => ['id' => $unidad->activo_id, 'nombre' => $unidad->activo?->nombre],
                'almacen' => ['id' => $unidad->almacen_id, 'nombre' => $unidad->almacen?->nombre],
                'empresa' => ['id' => $unidad->empresa_id, 'nombre_comercial' => $unidad->empresa?->nombre_comercial],
                'colaborador' => $unidad->colaborador === null ? null : [
                    'id' => $unidad->colaborador->id, 'nombre_completo' => $unidad->colaborador->nombre_completo,
                ],
                'registrado_por' => $unidad->registradoPor?->name,
                'creada_en' => $unidad->created_at?->toDateTimeString(),
            ],
            'movimientos' => $movimientos,
            'condicionesIncidencia' => collect(CondicionUnidadActivo::cases())->filter(fn ($c) => $c->esIncidencia())->values()
                ->map(fn ($c): array => ['valor' => $c->value, 'etiqueta' => $c->etiqueta()]),
            'condicionesRecuperacion' => collect(CondicionUnidadActivo::cases())->filter(fn ($c) => ! $c->esIncidencia())->values()
                ->map(fn ($c): array => ['valor' => $c->value, 'etiqueta' => $c->etiqueta()]),
            'permisos' => [
                'administrar' => $request->user()->can('administrar', $unidad),
            ],
        ]);
    }

    /**
     * Búsqueda de unidades entregables de un Activo (para el futuro flujo de
     * Entregas/Conjuntos). Requiere `activo_id`.
     */
    /**
     * Unidades ENTREGABLES (en almacén y funcionando) de un activo, para el
     * selector de "unidad concreta" de Entregas/Conjuntos. `almacen_id` es
     * opcional (lista de Unidades) pero se exige efectivamente en el flujo de
     * Entregas, porque el stock sale de un almacén concreto.
     */
    public function buscar(Request $request): JsonResponse
    {
        $this->authorize('viewAny', UnidadActivo::class);

        $activoId = (int) $request->query('activo_id', 0);

        if ($activoId <= 0) {
            return response()->json(['unidades' => []]);
        }

        $activo = Activo::query()->find($activoId);

        if ($activo === null || ! $request->user()->puedeAccederEmpresa($activo->empresa_id)) {
            return response()->json(['unidades' => []]);
        }

        $almacenId = $request->filled('almacen_id') ? (int) $request->query('almacen_id') : null;
        $termino = trim((string) $request->query('q', ''));

        $unidades = UnidadActivo::query()
            ->where('activo_id', $activoId)
            ->when($almacenId !== null, fn (Builder $q) => $q->where('almacen_id', $almacenId))
            ->where('estado', EstadoUnidadActivo::EnAlmacen)
            ->where('condicion', CondicionUnidadActivo::Funcionando)
            ->when($termino !== '', fn (Builder $q) => $q->where('codigo', 'like', "%{$termino}%"))
            ->orderBy('codigo')
            ->limit(30)
            ->get(['id', 'codigo'])
            ->map(fn (UnidadActivo $u): array => ['id' => $u->id, 'codigo' => $u->codigo]);

        return response()->json(['unidades' => $unidades]);
    }

    public function darDeBaja(DarDeBajaUnidadRequest $request, UnidadActivo $unidad, DarDeBajaUnidadActivo $accion): RedirectResponse
    {
        $accion->ejecutar($unidad, $request->string('motivo')->toString(), $request->user()?->id);

        return back()->with('toast', ['type' => 'success', 'message' => 'Unidad dada de baja.']);
    }

    /**
     * Reporta pérdida/robo de una unidad ASIGNADA. Nunca representa una
     * devolución física — ver `App\Acciones\MarcarUnidadIncidencia`.
     */
    public function marcarIncidencia(MarcarIncidenciaUnidadRequest $request, UnidadActivo $unidad, MarcarUnidadIncidencia $accion): RedirectResponse
    {
        $datos = $request->validated();

        $accion->ejecutar(
            $unidad,
            CondicionUnidadActivo::from($datos['tipo']),
            $datos['motivo'],
            $datos['observacion'] ?? null,
            $request->user()?->id,
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Incidencia registrada.']);
    }

    /**
     * Único camino explícito para que una unidad Perdida/Robada vuelva a
     * operar — ver `App\Acciones\RecuperarUnidadActivo`.
     */
    public function recuperar(RecuperarUnidadRequest $request, UnidadActivo $unidad, RecuperarUnidadActivo $accion): RedirectResponse
    {
        $datos = $request->validated();
        $almacen = Almacen::query()->findOrFail((int) $datos['almacen_id']);

        $accion->ejecutar(
            $unidad,
            $almacen,
            CondicionUnidadActivo::from($datos['condicion_resultante']),
            $datos['notas'] ?? null,
            $request->user()?->id,
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Unidad recuperada.']);
    }

    /**
     * PDF de etiquetas QR para las unidades seleccionadas. Acción distinta de
     * "Exportar PDF" de reporte: genera etiquetas imprimibles, no un listado.
     */
    public function generarEtiquetas(Request $request, ServicioEtiquetasQr $qr): HttpResponse
    {
        $this->authorize('viewAny', UnidadActivo::class);

        $ids = collect(explode(',', (string) $request->query('ids', '')))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        abort_if($ids->isEmpty(), 422, 'Selecciona al menos una unidad.');

        $idsAutorizadas = $this->idsEmpresasAutorizadas($request);

        $unidades = UnidadActivo::query()
            ->whereIn('id', $ids)
            ->whereIn('empresa_id', $idsAutorizadas)
            ->with(['activo:id,nombre', 'empresa:id,codigo'])
            ->get();

        abort_if($unidades->isEmpty(), 404);

        $pdf = Pdf::loadView('reportes.etiquetas_unidades', [
            'unidades' => $unidades,
            'qr' => $qr,
        ])->setPaper('letter');

        return $pdf->stream('etiquetas-unidades.pdf');
    }
}
