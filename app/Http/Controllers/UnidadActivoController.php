<?php

namespace App\Http\Controllers;

use App\Acciones\DarDeBajaUnidadActivo;
use App\Acciones\MarcarUnidadIncidencia;
use App\Acciones\RecuperarUnidadActivo;
use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoUnidadActivo;
use App\Enums\EstadoVisibleUnidad;
use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Controllers\Concerns\ExportaListado;
use App\Http\Requests\Activos\ActualizarEspecificacionUnidadRequest;
use App\Http\Requests\Activos\DarDeBajaUnidadRequest;
use App\Http\Requests\Activos\MarcarIncidenciaUnidadRequest;
use App\Http\Requests\Activos\RecuperarUnidadRequest;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\MovimientoInventario;
use App\Models\UnidadActivo;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioEtiquetasQr;
use App\Soporte\ContextoExportacion;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
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
                'ubicacion_operativa' => $u->ubicacionOperativa(),
                'estado' => $u->estado->value,
                'estado_etiqueta' => $u->estado->etiqueta(),
                'condicion' => $u->condicion->value,
                'condicion_etiqueta' => $u->condicion->etiqueta(),
                'estado_visible' => $u->estadoVisible()->value,
                'estado_visible_etiqueta' => $u->estadoVisible()->etiqueta(),
                'entregable' => $u->esEntregable(),
                'marca_modelo' => $u->especificacion?->marcaModelo(),
                'imei_mascara' => $u->especificacion?->imeiMascara(),
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
                'estado_visible' => $filtros['estado_visible'] ?? '',
                'contrato_id' => $filtros['contrato_id'] ?? null,
                'servicio_id' => $filtros['servicio_id'] ?? null,
            ],
            'estadosVisibles' => collect(EstadoVisibleUnidad::cases())
                ->map(fn (EstadoVisibleUnidad $e): array => ['valor' => $e->value, 'etiqueta' => $e->etiqueta()]),
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
        $empresaFiltro = $this->empresaDelFiltro($request);
        $unidades = $this->consultaUnidades($request, $filtros)->get();

        $filas = $unidades->map(function (UnidadActivo $u): array {
            $ubicacion = $u->ubicacionOperativa();
            $servicioTexto = match ($ubicacion['tipo']) {
                'servicio' => $ubicacion['contrato'].' — '.$ubicacion['servicio'],
                'sin_servicio' => 'Sin servicio asignado',
                default => null,
            };

            $esp = $u->especificacion;

            return [
                $u->codigo,
                $u->activo?->nombre,
                $u->almacen?->nombre,
                $u->colaborador?->nombre_completo,
                $servicioTexto,
                $u->estadoVisible()->etiqueta(),
                $u->estado->etiqueta(),
                $u->condicion->etiqueta(),
                $esp?->marca,
                $esp?->modelo,
                $esp?->imei,
                $esp?->numero_telefonico,
                $esp?->operador,
                $esp?->plan,
            ];
        })->all();

        $filtrosHumanos = array_filter([
            'Búsqueda' => $filtros['buscar'] ?? null,
            'Activo' => ($filtros['activo_id'] ?? null) ? Activo::query()->find((int) $filtros['activo_id'])?->nombre : null,
            'Almacén' => ($filtros['almacen_id'] ?? null) ? Almacen::query()->find((int) $filtros['almacen_id'])?->nombre : null,
            'Estado' => ($filtros['estado'] ?? null) ? EstadoUnidadActivo::from($filtros['estado'])->etiqueta() : null,
            'Condición' => ($filtros['condicion'] ?? null) ? CondicionUnidadActivo::from($filtros['condicion'])->etiqueta() : null,
            'Posesión' => ($filtros['estado_visible'] ?? null) ? EstadoVisibleUnidad::from($filtros['estado_visible'])->etiqueta() : null,
        ]);

        $contexto = new ContextoExportacion('Unidades identificadas', $empresaFiltro, $filtrosHumanos, $unidades->count());

        return $this->respuestaExportacion($request->input('formato', 'xlsx'), $filas, [
            'Código', 'Activo', 'Almacén', 'Colaborador', 'Servicio', 'Estado', 'Posesión', 'Condición',
            'Marca', 'Modelo', 'IMEI', 'Número', 'Operador', 'Plan',
        ], $contexto);
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
            'estado_visible' => ['nullable', Rule::enum(EstadoVisibleUnidad::class)],
            'contrato_id' => ['nullable', 'integer'],
            'servicio_id' => ['nullable', 'integer'],
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
            ->with([
                'activo:id,nombre,codigo', 'almacen:id,nombre',
                'colaborador:id,nombre_completo,servicio_actual_id',
                'colaborador.servicioActual:id,nombre,contrato_id',
                'colaborador.servicioActual.contrato:id,nombre',
                'especificacion',
            ])
            ->when($filtros['buscar'] ?? null, function (Builder $q, string $buscar): void {
                $q->where(function (Builder $sub) use ($buscar): void {
                    $sub->where('codigo', 'like', "%{$buscar}%")
                        ->orWhereHas('activo', fn (Builder $a) => $a->where('nombre', 'like', "%{$buscar}%"))
                        ->orWhereHas('especificacion', fn (Builder $e) => $e
                            ->where('imei', 'like', "%{$buscar}%")
                            ->orWhere('numero_telefonico', 'like', "%{$buscar}%")
                            ->orWhere('marca', 'like', "%{$buscar}%")
                            ->orWhere('modelo', 'like', "%{$buscar}%"));
                });
            })
            ->when($filtros['activo_id'] ?? null, fn (Builder $q, $v) => $q->where('activo_id', $v))
            ->when($filtros['almacen_id'] ?? null, fn (Builder $q, $v) => $q->where('almacen_id', $v))
            ->when($filtros['estado'] ?? null, fn (Builder $q, $v) => $q->where('estado', $v))
            ->when($filtros['condicion'] ?? null, fn (Builder $q, $v) => $q->where('condicion', $v))
            ->when($filtros['estado_visible'] ?? null, fn (Builder $q, $v) => $this->aplicarFiltroEstadoVisible($q, $v))
            // Filtra por la ubicación operativa VIGENTE del colaborador
            // asignado (nunca por un dato propio de la unidad — no existe).
            ->when($filtros['servicio_id'] ?? null, fn (Builder $q, $v) => $q->whereHas('colaborador', fn (Builder $c) => $c->where('servicio_actual_id', $v)))
            ->when($filtros['contrato_id'] ?? null, fn (Builder $q, $v) => $q->whereHas('colaborador.servicioActual', fn (Builder $s) => $s->where('contrato_id', $v)))
            ->orderByDesc('id');
    }

    /**
     * Traduce el estado VISIBLE consolidado (ver `EstadoVisibleUnidad`) a
     * condiciones SQL sobre las columnas reales `estado`/`condicion`, con la
     * misma prioridad que `UnidadActivo::estadoVisible()` — nunca al revés,
     * para no tener dos fuentes de verdad del mismo cálculo.
     *
     * @param  Builder<UnidadActivo>  $q
     */
    private function aplicarFiltroEstadoVisible(Builder $q, string $valor): void
    {
        match (EstadoVisibleUnidad::from($valor)) {
            EstadoVisibleUnidad::Baja => $q->where('estado', EstadoUnidadActivo::Baja),
            EstadoVisibleUnidad::Robado => $q->where('estado', '!=', EstadoUnidadActivo::Baja)
                ->where('condicion', CondicionUnidadActivo::Robado),
            EstadoVisibleUnidad::Perdido => $q->where('estado', '!=', EstadoUnidadActivo::Baja)
                ->where('condicion', CondicionUnidadActivo::Perdido),
            EstadoVisibleUnidad::Reparacion => $q->where('estado', '!=', EstadoUnidadActivo::Baja)
                ->whereIn('condicion', [CondicionUnidadActivo::EnReparacion, CondicionUnidadActivo::Inservible]),
            EstadoVisibleUnidad::Asignado => $q->where('estado', EstadoUnidadActivo::Asignada)
                ->where('condicion', CondicionUnidadActivo::Funcionando),
            EstadoVisibleUnidad::Disponible => $q->where('estado', EstadoUnidadActivo::EnAlmacen)
                ->where('condicion', CondicionUnidadActivo::Funcionando),
        };
    }

    public function show(Request $request, UnidadActivo $unidad): Response
    {
        $this->authorize('view', $unidad);

        $unidad->load([
            'activo:id,nombre,codigo,tipo_control,categoria_id',
            'activo.categoriaActivo.perfilTecnico',
            'almacen:id,nombre', 'empresa:id,nombre_comercial',
            'colaborador:id,nombre_completo,servicio_actual_id',
            'colaborador.servicioActual:id,nombre,contrato_id',
            'colaborador.servicioActual.contrato:id,nombre',
            'registradoPor:id,name',
            'especificacion',
        ]);

        $perfil = $unidad->perfilTecnico();

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
                'estado_visible' => $unidad->estadoVisible()->value,
                'estado_visible_etiqueta' => $unidad->estadoVisible()->etiqueta(),
                'estado_visible_descripcion' => $unidad->estadoVisible()->descripcion(),
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
                'ubicacion_operativa' => $unidad->ubicacionOperativa(),
                'registrado_por' => $unidad->registradoPor?->name,
                'creada_en' => $unidad->created_at?->toDateTimeString(),
                // Datos técnicos del equipo (Celular / Computadora / Tablet).
                'perfil_tecnico' => $perfil?->value,
                'perfil_tecnico_etiqueta' => $perfil?->etiqueta(),
                'datos_equipo' => $unidad->datosEquipo(),
                'especificacion' => $perfil === null || $unidad->especificacion === null ? null : $unidad->especificacion->only([
                    'marca', 'modelo', 'imei', 'numero_telefonico', 'operador', 'plan',
                ]),
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
     * PNG del código QR de UNA unidad, para verlo/imprimirlo en cualquier
     * momento después de crearla. El QR no es un archivo persistido: se
     * deriva en vivo del `public_token` (permanente y único). La operación es
     * de sólo lectura → idempotente, sin concurrencia, sin duplicados y sin
     * tocar el `codigo` estable de la unidad. La ruta enlaza por
     * `public_token` (no por id) y la Policy revalida el acceso a la empresa
     * de la unidad (defensa IDOR).
     */
    public function qr(UnidadActivo $unidad, ServicioEtiquetasQr $qr): HttpResponse
    {
        $this->authorize('view', $unidad);

        return response($qr->pngBytes($unidad), 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline; filename="qr-'.$unidad->codigo.'.png"',
            'Cache-Control' => 'private, max-age=86400',
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
        // El flujo de Traspasos pide sólo unidades realmente disponibles (en
        // almacén, funcionando, sin asignar). Entregas NO manda el flag: ahí
        // se muestran también las no entregables, deshabilitadas con el motivo.
        $soloDisponibles = $request->boolean('solo_disponibles');

        $unidades = UnidadActivo::query()
            ->where('activo_id', $activoId)
            ->when($almacenId !== null, fn (Builder $q) => $q->where('almacen_id', $almacenId))
            ->when($soloDisponibles, fn (Builder $q) => $q
                ->where('estado', EstadoUnidadActivo::EnAlmacen->value)
                ->where('condicion', CondicionUnidadActivo::Funcionando->value)
                ->whereNull('colaborador_id'))
            ->when($termino !== '', fn (Builder $q) => $q->where(fn (Builder $s) => $s
                ->where('codigo', 'like', "%{$termino}%")
                ->orWhere('observaciones', 'like', "%{$termino}%")
                ->orWhereHas('activo', fn (Builder $a) => $a->where('nombre', 'like', "%{$termino}%"))
                ->orWhereHas('especificacion', fn (Builder $e) => $e
                    ->where('imei', 'like', "%{$termino}%")
                    ->orWhere('numero_telefonico', 'like', "%{$termino}%")
                    ->orWhere('marca', 'like', "%{$termino}%")
                    ->orWhere('modelo', 'like', "%{$termino}%"))))
            ->orderByRaw("case when estado = 'en_almacen' and condicion = 'funcionando' then 0 else 1 end")
            ->orderBy('codigo')
            ->limit(30)
            ->with(['activo:id,nombre', 'almacen:id,nombre', 'especificacion'])
            ->get(['id', 'codigo', 'estado', 'condicion', 'activo_id', 'almacen_id', 'colaborador_id', 'observaciones'])
            ->map(fn (UnidadActivo $u): array => [
                'id' => $u->id,
                'codigo' => $u->codigo,
                'activo' => $u->activo?->nombre,
                'almacen' => $u->almacen?->nombre,
                'observaciones' => Str::limit((string) $u->observaciones, 60) ?: null,
                'marca_modelo' => $u->especificacion?->marcaModelo(),
                'imei_mascara' => $u->especificacion?->imeiMascara(),
                'numero_telefonico' => $u->especificacion?->numero_telefonico,
                'estado_visible_etiqueta' => $u->estadoVisible()->etiqueta(),
                'condicion_etiqueta' => $u->condicion->etiqueta(),
                'entregable' => $u->esEntregable(),
                'motivo_no_entregable' => $u->esEntregable() ? null : $this->motivoNoEntregable($u),
            ]);

        return response()->json(['unidades' => $unidades]);
    }

    private function motivoNoEntregable(UnidadActivo $unidad): string
    {
        return match (true) {
            $unidad->estado === EstadoUnidadActivo::Baja => 'Dada de baja',
            $unidad->estado === EstadoUnidadActivo::Asignada => 'Asignada a un colaborador',
            $unidad->condicion === CondicionUnidadActivo::EnReparacion => 'En reparación',
            $unidad->condicion === CondicionUnidadActivo::Perdido => 'Perdida',
            $unidad->condicion === CondicionUnidadActivo::Robado => 'Robada',
            $unidad->condicion === CondicionUnidadActivo::Inservible => 'Inservible',
            default => 'No disponible',
        };
    }

    /**
     * Edita los datos técnicos (marca / modelo / IMEI / número / operador /
     * plan) de una unidad. No toca `codigo` / `public_token` / `estado` /
     * `condicion` — el QR y el código son permanentes.
     */
    public function actualizarEspecificacion(
        ActualizarEspecificacionUnidadRequest $request,
        UnidadActivo $unidad,
        ServicioAuditoria $auditoria,
    ): RedirectResponse {
        $unidad->loadMissing('especificacion');
        $datos = $request->validated();
        $datos = array_map(fn ($v) => is_string($v) && trim($v) === '' ? null : $v, $datos);

        $anteriores = $unidad->especificacion?->only(array_keys($datos)) ?? [];

        $unidad->especificacion()->updateOrCreate(['unidad_activo_id' => $unidad->id], $datos);

        $auditoria->registrar('activos', 'unidad_especificacion', [
            'tipo_entidad' => UnidadActivo::class,
            'entidad_id' => $unidad->id,
            'empresa_id' => $unidad->empresa_id,
            'descripcion' => 'Datos técnicos actualizados de la unidad '.$unidad->codigo,
            'valores_anteriores' => $anteriores,
            'valores_nuevos' => $datos,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Datos del equipo actualizados.']);
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
            ->with(['activo:id,nombre', 'empresa:id,nombre_comercial'])
            ->get();

        abort_if($unidades->isEmpty(), 404);

        $pdf = Pdf::loadView('reportes.etiquetas_unidades', [
            'unidades' => $unidades,
            'qr' => $qr,
        ])->setPaper('letter');

        return $pdf->stream('etiquetas-unidades.pdf');
    }
}
