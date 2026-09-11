<?php

namespace App\Http\Controllers;

use App\Acciones\CrearActivoConExistencias;
use App\Acciones\RegistrarEntradaInventario;
use App\Acciones\RegistrarUnidadesActivo;
use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoUnidadActivo;
use App\Enums\TipoControlActivo;
use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Controllers\Concerns\CreaConCodigoUnico;
use App\Http\Controllers\Concerns\ReactivaSuspendidos;
use App\Http\Controllers\Concerns\ReconciliaSecuenciaCodigo;
use App\Http\Requests\Activos\AgregarExistenciasRequest;
use App\Http\Requests\Activos\GuardarActivoRequest;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\CategoriaActivo;
use App\Models\Conjunto;
use App\Models\Empresa;
use App\Models\SaldoInventario;
use App\Models\Talla;
use App\Models\TipoActivo;
use App\Models\UnidadActivo;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioCascadaSuspension;
use App\Soporte\ResolverPerfilTecnicoUnidad;
use App\Soporte\ServicioGeneradorCodigos;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Catálogo de activos por empresa. La empresa llega como filtro (listado) o
 * campo `empresa_id` (alta), y siempre se valida el acceso del usuario.
 */
class ActivoController extends Controller
{
    use ConEmpresa;
    use CreaConCodigoUnico;
    use ReactivaSuspendidos;
    use ReconciliaSecuenciaCodigo;

    public function __construct(
        private readonly ServicioAuditoria $auditoria,
        private readonly ServicioCascadaSuspension $cascada,
        private readonly ServicioGeneradorCodigos $codigos,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Activo::class);

        $idsAutorizadas = $this->idsEmpresasAutorizadas($request);
        $empresaFiltro = $this->empresaDelFiltro($request);
        $idsScope = $empresaFiltro !== null ? collect([$empresaFiltro->id]) : $idsAutorizadas;

        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'tipo_activo_id' => ['nullable', 'integer'],
            'categoria_id' => ['nullable', 'integer'],
            'almacen_id' => ['nullable', 'integer'],
            'control' => ['nullable', Rule::in(['cantidad', 'individual'])],
            'estado' => ['nullable', Rule::in(['activos', 'inactivos'])],
            'orden' => ['nullable', Rule::in(['az', 'za'])],
        ]);

        $orden = ($filtros['orden'] ?? 'az') === 'za' ? 'desc' : 'asc';

        // Sólo quien puede administrar activos puede verlos eliminados en el
        // listado. Para el resto, "activo" se fuerza sin importar qué
        // `estado` pida la URL.
        $puedeVerEliminados = $request->user()->can('activos.administrar');

        $existencias = SaldoInventario::query()
            ->whereIn('empresa_id', $idsScope)
            ->selectRaw('activo_id, SUM(cantidad) as total, SUM(CASE WHEN minimo > 0 AND cantidad <= minimo THEN 1 ELSE 0 END) as tallas_bajo_minimo')
            ->groupBy('activo_id')
            ->get()
            ->keyBy('activo_id');

        $activos = Activo::query()
            ->whereIn('empresa_id', $idsScope)
            ->with(['tallas:id,valor', 'tipoActivo:id,nombre', 'empresa:id,nombre_comercial'])
            ->when($filtros['buscar'] ?? null, function (Builder $q, string $buscar): void {
                $q->where(function (Builder $sub) use ($buscar): void {
                    $sub->where('nombre', 'like', "%{$buscar}%")
                        ->orWhere('codigo', 'like', "%{$buscar}%")
                        ->orWhere('categoria', 'like', "%{$buscar}%");
                });
            })
            ->when($filtros['tipo_activo_id'] ?? null, fn (Builder $q, $v) => $q->where('tipo_activo_id', $v))
            ->when($filtros['categoria_id'] ?? null, fn (Builder $q, $v) => $q->where('categoria_id', $v))
            ->when($filtros['almacen_id'] ?? null, function (Builder $q, $almacenId): void {
                $q->whereHas('saldos', fn (Builder $s) => $s->where('almacen_id', $almacenId)->where('cantidad', '>', 0));
            })
            ->when($filtros['control'] ?? null, fn (Builder $q, $v) => $q->where('tipo_control', $v))
            ->when(! $puedeVerEliminados, fn (Builder $q) => $q->where('activo', true))
            ->when($puedeVerEliminados && ($filtros['estado'] ?? null) === 'activos', fn (Builder $q) => $q->where('activo', true))
            ->when($puedeVerEliminados && ($filtros['estado'] ?? null) === 'inactivos', fn (Builder $q) => $q->where('activo', false))
            ->orderBy('nombre', $orden)
            ->get()
            ->map(fn (Activo $a): array => [
                'id' => $a->id,
                'nombre' => $a->nombre,
                'categoria' => $a->categoria,
                'codigo' => $a->codigo,
                'empresa' => ['id' => $a->empresa_id, 'nombre_comercial' => $a->empresa?->nombre_comercial],
                'tipo' => $a->tipoActivo?->nombre,
                'tipo_control' => $a->tipo_control->value,
                'tipo_control_etiqueta' => $a->tipo_control->etiqueta(),
                'activo' => $a->activo,
                'imagen_url' => $a->imagen_ruta ? Storage::disk('public')->url($a->imagen_ruta) : null,
                'tallas' => $a->tallas->pluck('valor'),
                'existencias' => (int) ($existencias[$a->id]->total ?? 0),
                'tallas_bajo_minimo' => (int) ($existencias[$a->id]->tallas_bajo_minimo ?? 0),
            ]);

        return Inertia::render('Activos/Index', [
            'activos' => $activos,
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
            'filtrosSeleccion' => [
                'tipo' => ($filtros['tipo_activo_id'] ?? null)
                    ? TipoActivo::query()->whereKey($filtros['tipo_activo_id'])->first(['id', 'nombre'])
                    : null,
                'categoria' => ($filtros['categoria_id'] ?? null)
                    ? CategoriaActivo::query()->whereKey($filtros['categoria_id'])->first(['id', 'nombre', 'tipo_activo_id'])
                    : null,
                'almacen' => ($filtros['almacen_id'] ?? null)
                    ? Almacen::query()->whereKey($filtros['almacen_id'])->first(['id', 'nombre', 'codigo'])
                    : null,
            ],
            'filtros' => [
                'buscar' => $filtros['buscar'] ?? '',
                'empresa_id' => $empresaFiltro?->id,
                'tipo_activo_id' => $filtros['tipo_activo_id'] ?? '',
                'categoria_id' => $filtros['categoria_id'] ?? '',
                'almacen_id' => $filtros['almacen_id'] ?? '',
                'control' => $filtros['control'] ?? '',
                'estado' => $filtros['estado'] ?? '',
                'orden' => $filtros['orden'] ?? 'az',
            ],
            'permisos' => [
                'crear' => $request->user()->can('create', Activo::class),
                'editar' => $request->user()->can('activos.editar'),
                'administrar' => $request->user()->can('activos.administrar'),
                'administrar_catalogos' => $request->user()->can('administrar', TipoActivo::class),
                'verEliminados' => $puedeVerEliminados,
            ],
        ]);
    }

    /**
     * Búsqueda con autocompletado para los combobox de activos. Requiere
     * `empresa_id`: el activo pertenece a una empresa concreta.
     */
    /**
     * Búsqueda de activos de UNA empresa. Cuando viene `almacen_id`, cada
     * resultado incluye la disponibilidad REAL en ESE almacén (nunca el total
     * general ni el de otro almacén) — por variante cuando aplique, o el
     * conteo de unidades entregables para seguimiento individual — así el
     * selector de Entregas puede deshabilitar/explicar lo que no tiene
     * existencias en vez de dejarlo seleccionable a ciegas.
     */
    public function buscar(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Activo::class);

        $empresa = $this->empresaDelFiltro($request);

        if ($empresa === null) {
            return response()->json(['activos' => []]);
        }

        $termino = trim((string) $request->query('q', ''));
        $control = $request->query('control');
        $almacenId = $request->filled('almacen_id') ? (int) $request->query('almacen_id') : null;

        $activos = Activo::query()
            ->where('empresa_id', $empresa->id)
            ->where('activo', true)
            ->when(in_array($control, ['cantidad', 'individual'], true), fn (Builder $q) => $q->where('tipo_control', $control))
            ->withCount('tallas')
            ->with(['tipoActivo:id,nombre', 'categoriaActivo:id,nombre'])
            ->when($termino !== '', function (Builder $q) use ($termino): void {
                $q->where(function (Builder $sub) use ($termino): void {
                    $sub->where('nombre', 'like', "%{$termino}%")
                        ->orWhere('codigo', 'like', "%{$termino}%")
                        ->orWhere('categoria', 'like', "%{$termino}%")
                        ->orWhereHas('tipoActivo', fn (Builder $t) => $t->where('nombre', 'like', "%{$termino}%"))
                        ->orWhereHas('categoriaActivo', fn (Builder $c) => $c->where('nombre', 'like', "%{$termino}%"));
                });
            })
            ->orderBy('nombre')
            ->limit(20)
            ->get()
            ->map(function (Activo $a) use ($almacenId): array {
                $tallas = $a->tallasElegibles()
                    ->map(fn (Talla $t): array => ['id' => $t->id, 'valor' => $t->valor])
                    ->values();

                $fila = [
                    'id' => $a->id,
                    'nombre' => $a->nombre,
                    'codigo' => $a->codigo,
                    'tipo' => $a->tipoActivo?->nombre,
                    'categoria' => $a->categoriaActivo?->nombre,
                    'control' => $a->tipo_control->value,
                    // `usa_variantes`: el activo tiene variantes asociadas (crudo).
                    // `tallas`: sólo las elegibles (asociadas y activas). Si
                    // `usa_variantes` y `tallas` está vacío → todas sus variantes
                    // están desactivadas.
                    'usa_variantes' => (int) $a->tallas_count > 0,
                    'tallas' => $tallas,
                ];

                if ($almacenId === null) {
                    return $fila;
                }

                if ($a->tipo_control === TipoControlActivo::SeguimientoIndividual) {
                    $fila['disponible'] = UnidadActivo::query()
                        ->where('activo_id', $a->id)
                        ->where('almacen_id', $almacenId)
                        ->where('estado', EstadoUnidadActivo::EnAlmacen)
                        ->where('condicion', CondicionUnidadActivo::Funcionando)
                        ->count();

                    return $fila;
                }

                $saldosPorTalla = SaldoInventario::query()
                    ->where('empresa_id', $a->empresa_id)
                    ->where('almacen_id', $almacenId)
                    ->where('activo_id', $a->id)
                    ->get(['talla_id', 'cantidad'])
                    ->keyBy(fn (SaldoInventario $s) => $s->talla_id ?? 0);

                $fila['disponible'] = (int) $saldosPorTalla->sum('cantidad');
                $fila['tallas'] = $tallas->map(function (array $t) use ($saldosPorTalla): array {
                    $saldo = $saldosPorTalla->get($t['id']);
                    $t['disponible'] = $saldo !== null ? (int) $saldo->cantidad : 0;

                    return $t;
                })->values();

                return $fila;
            });

        return response()->json(['activos' => $activos]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Activo::class);

        return Inertia::render('Activos/Formulario', [
            'activo' => null,
            'seleccion' => ['tipo' => null, 'categoria' => null],
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
            'tallasGlobales' => $this->tallasGlobales(),
            'tallasAsignadas' => [],
            'tiposControl' => TipoControlActivo::opciones(),
            'permisos' => $this->permisosCatalogos($request),
        ]);
    }

    public function store(GuardarActivoRequest $request, CrearActivoConExistencias $accion): RedirectResponse
    {
        $empresa = $request->empresaResuelta();

        $rutaImagen = $request->hasFile('imagen')
            ? ($request->file('imagen')->store("activos/{$empresa->id}", 'public') ?: null)
            : null;

        $tallaIds = array_map('intval', $request->input('tallas', []));

        $resultado = $this->crearConCodigoUnico(function () use ($request, $empresa, $rutaImagen, $tallaIds, $accion): array {
            $datosActivo = [
                'tipo_activo_id' => $request->integer('tipo_activo_id') ?: null,
                ...$this->datosCategoria($request, $empresa->id),
                'nombre' => $request->string('nombre'),
                'descripcion' => $request->input('descripcion'),
                'tipo_control' => (string) $request->string('tipo_control'),
                'codigo' => $this->generarCodigo($empresa),
                'activo' => $request->boolean('activo', true),
                'imagen_ruta' => $rutaImagen,
            ];

            return $accion->ejecutar(
                empresaId: $empresa->id,
                datosActivo: $datosActivo,
                tallaIds: $tallaIds,
                almacenId: $request->integer('almacen_id') ?: null,
                existenciaInicial: $this->existenciaInicialDesdeRequest($request, $tallaIds),
                cantidadUnidades: (int) $request->input('cantidad_inicial', 0),
                realizadoPor: $request->user()?->id,
                especificaciones: array_values((array) $request->input('especificaciones', [])),
            );
        });
        $activo = $resultado['activo'];

        $this->auditoria->registrar('activos', 'crear', [
            'tipo_entidad' => Activo::class, 'entidad_id' => $activo->id, 'empresa_id' => $empresa->id,
            'descripcion' => 'Alta de activo '.$activo->nombre,
        ]);

        if ($request->boolean('abrir_etiquetas') && $resultado['unidades']->isNotEmpty()) {
            $ids = $resultado['unidades']->pluck('id')->implode(',');

            // El PDF de etiquetas NUNCA se devuelve en la respuesta de esta
            // petición Inertia (el cliente la renderizaría como si fuera una
            // página, mostrando los bytes crudos). Se deja la URL en flash;
            // el cliente la abre aparte tras la redirección normal.
            return to_route('activos.show', $activo)
                ->with('toast', ['type' => 'success', 'message' => 'Activo creado correctamente.'])
                ->with('etiquetasUrl', route('unidades-activo.etiquetas', ['ids' => $ids]));
        }

        return to_route('activos.index')->with('toast', ['type' => 'success', 'message' => 'Activo creado.']);
    }

    public function edit(Request $request, Activo $activo): Response
    {
        $this->authorize('update', $activo);

        $activo->load('tipoActivo:id,nombre', 'categoriaActivo:id,nombre,tipo_activo_id');

        return Inertia::render('Activos/Formulario', [
            'activo' => [
                ...$activo->only(['id', 'nombre', 'descripcion', 'codigo', 'empresa_id', 'tipo_activo_id', 'categoria_id', 'activo']),
                'tipo_control' => $activo->tipo_control->value,
                'imagen_url' => $activo->imagen_ruta ? Storage::disk('public')->url($activo->imagen_ruta) : null,
                'tallas' => $activo->tallas()->pluck('tallas.id'),
            ],
            // El tipo / la categoría asignados se muestran aunque estén
            // desactivados (los buscadores sólo ofrecen los activos).
            'seleccion' => [
                'tipo' => $activo->tipoActivo === null ? null : [
                    'id' => $activo->tipoActivo->id, 'nombre' => $activo->tipoActivo->nombre,
                ],
                'categoria' => $activo->categoriaActivo === null ? null : [
                    'id' => $activo->categoriaActivo->id,
                    'nombre' => $activo->categoriaActivo->nombre,
                    'tipo_activo_id' => $activo->categoriaActivo->tipo_activo_id,
                ],
            ],
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
            'tallasGlobales' => $this->tallasGlobales(),
            // Variantes ya asignadas al activo: las desactivadas globalmente
            // siguen visibles (histórico) para poder quitarlas, marcadas como
            // tales.
            'tallasAsignadas' => $activo->tallas()->orderBy('tallas.orden')->orderBy('tallas.valor')
                ->get(['tallas.id', 'valor', 'activa'])
                ->map(fn (Talla $t): array => [
                    'id' => $t->id,
                    'valor' => $t->valor,
                    'habilitada' => $t->activa,
                ])->values(),
            'tiposControl' => TipoControlActivo::opciones(),
            'permisos' => $this->permisosCatalogos($request),
        ]);
    }

    public function update(GuardarActivoRequest $request, Activo $activo): RedirectResponse
    {
        $activo->fill([
            'tipo_activo_id' => $request->integer('tipo_activo_id') ?: null,
            ...$this->datosCategoria($request, $activo->empresa_id),
            'nombre' => $request->string('nombre'),
            'descripcion' => $request->input('descripcion'),
            'tipo_control' => (string) $request->string('tipo_control'),
            // `codigo` es inmutable: nunca se acepta un valor del cliente,
            // ni en alta ni en edición.
            'activo' => $request->boolean('activo', $activo->activo),
        ]);

        // El archivo anterior se borra SIEMPRE después de `save()`, nunca antes:
        // si la escritura en BD falla, el activo conserva su imagen vigente.
        $rutaImagenABorrar = null;
        $imagenEliminada = false;

        if ($request->hasFile('imagen')) {
            $rutaNueva = $request->file('imagen')->store("activos/{$activo->empresa_id}", 'public');

            if ($rutaNueva !== false) {
                $rutaImagenABorrar = $activo->imagen_ruta;
                $activo->imagen_ruta = $rutaNueva;
            }
        } elseif ($request->boolean('eliminar_imagen') && $activo->imagen_ruta) {
            $rutaImagenABorrar = $activo->imagen_ruta;
            $activo->imagen_ruta = null;
            $imagenEliminada = true;
        }

        $activo->save();
        $activo->tallas()->sync($request->input('tallas', []));

        if ($rutaImagenABorrar) {
            Storage::disk('public')->delete($rutaImagenABorrar);
        }

        $this->auditoria->registrar('activos', 'editar', [
            'tipo_entidad' => Activo::class, 'entidad_id' => $activo->id, 'empresa_id' => $activo->empresa_id,
            'descripcion' => 'Edición de activo '.$activo->nombre.($imagenEliminada ? ' · imagen eliminada' : ''),
        ]);

        return to_route('activos.index')->with('toast', ['type' => 'success', 'message' => 'Activo actualizado.']);
    }

    public function toggle(Request $request, Activo $activo): RedirectResponse
    {
        $this->authorize('administrar', $activo);

        $activo->update(['activo' => ! $activo->activo]);

        $mensaje = $activo->activo ? 'Activo restaurado.' : 'Activo eliminado.';

        if (! $activo->activo) {
            // Cascada NO destructiva: un conjunto que usa este activo como
            // componente ya no se puede armar completo, así que queda
            // suspendido (nunca los que ya estaban inactivos por otra causa).
            $suspendidos = $this->cascada->suspender(
                $activo,
                Conjunto::query()->whereHas('componentes', fn (Builder $q) => $q->where('activo_id', $activo->id)),
                'activo',
                $request->user()?->id,
            );

            if ($suspendidos > 0) {
                $mensaje .= " {$suspendidos} conjunto(s) que lo usan como componente quedaron suspendidos por cascada.";
            }
        }

        $this->auditoria->registrar('activos', $activo->activo ? 'activar' : 'desactivar', [
            'tipo_entidad' => Activo::class, 'entidad_id' => $activo->id, 'empresa_id' => $activo->empresa_id,
            'descripcion' => ($activo->activo ? 'Activación' : 'Desactivación').' de activo '.$activo->nombre,
            'valores_anteriores' => ['activo' => ! $activo->activo],
            'valores_nuevos' => ['activo' => $activo->activo],
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => $mensaje]);
    }

    public function show(Request $request, Activo $activo): Response
    {
        $this->authorize('view', $activo);

        $activo->load('tipoActivo:id,nombre', 'categoriaActivo.perfilTecnico', 'empresa:id,nombre_comercial');

        $esIndividual = $activo->tipo_control === TipoControlActivo::SeguimientoIndividual;
        $perfilTecnico = app(ResolverPerfilTecnicoUnidad::class)->paraActivo($activo);

        $saldos = $esIndividual ? collect() : SaldoInventario::query()
            ->where('empresa_id', $activo->empresa_id)
            ->where('activo_id', $activo->id)
            ->with(['almacen:id,nombre', 'talla:id,valor'])
            ->get()
            ->map(fn (SaldoInventario $s): array => [
                'almacen' => $s->almacen?->nombre,
                'talla' => $s->talla?->valor,
                'cantidad' => $s->cantidad,
                'minimo' => $s->minimo,
                'bajo_minimo' => $s->estaBajoMinimo(),
            ]);

        $resumenUnidades = ! $esIndividual ? null : $activo->unidades()
            ->selectRaw('estado, count(*) as total')
            ->groupBy('estado')
            ->get()
            ->mapWithKeys(fn (UnidadActivo $fila): array => [
                $fila->estado->value => (int) $fila->getAttribute('total'),
            ]);

        return Inertia::render('Activos/Detalle', [
            'activo' => [
                ...$activo->only(['id', 'nombre', 'descripcion', 'codigo', 'categoria', 'activo']),
                'empresa' => ['id' => $activo->empresa_id, 'nombre_comercial' => $activo->empresa?->nombre_comercial],
                'tipo' => $activo->tipoActivo?->nombre,
                'tipo_control' => $activo->tipo_control->value,
                'tipo_control_etiqueta' => $activo->tipo_control->etiqueta(),
                'perfil_tecnico' => $perfilTecnico?->value,
                'perfil_tecnico_etiqueta' => $perfilTecnico?->etiqueta(),
                'imagen_url' => $activo->imagen_ruta ? Storage::disk('public')->url($activo->imagen_ruta) : null,
                'tallas' => $activo->tallas()->pluck('valor'),
            ],
            'saldos' => $saldos,
            'usaVariantes' => $activo->tallas()->exists(),
            'resumenUnidades' => $resumenUnidades === null ? null : [
                'en_almacen' => (int) ($resumenUnidades['en_almacen'] ?? 0),
                'asignada' => (int) ($resumenUnidades['asignada'] ?? 0),
                'baja' => (int) ($resumenUnidades['baja'] ?? 0),
            ],
            'permisos' => [
                'editar' => $request->user()->can('update', $activo),
                'administrar' => $request->user()->can('administrar', $activo),
                'agregar_existencias' => $request->user()->can('inventario.entrada')
                    && $request->user()->can('update', $activo),
            ],
            'suspendidos' => $this->cascada->paraVista($this->cascada->checklistDe($activo)),
        ]);
    }

    /**
     * Reactivación selectiva (Fase 7): sólo levanta las suspensiones VIGENTES
     * causadas por ESTE activo (conjuntos que lo usan como componente) cuyo
     * id venga marcado, y sólo si el conjunto ya no depende de otro
     * componente inactivo ni de una empresa inactiva — blindaje multicausa.
     */
    public function reactivarSuspendidos(Request $request, Activo $activo): RedirectResponse
    {
        $this->authorize('administrar', $activo);

        $ids = array_map('intval', $request->input('ids', []));
        $resultado = $this->cascada->reactivarSeleccionados($activo, $ids, $request->user()?->id);

        return back()->with('toast', $this->toastDeReactivacion($resultado));
    }

    /**
     * "Agregar existencias" desde el contexto del Activo (detalle/edición),
     * sin navegar a otro módulo. Reutiliza `RegistrarEntradaInventario`.
     */
    public function agregarExistencias(
        AgregarExistenciasRequest $request,
        Activo $activo,
        RegistrarEntradaInventario $registrarEntrada,
        RegistrarUnidadesActivo $registrarUnidades,
    ): RedirectResponse {
        $motivo = $request->input('motivo') ?: 'Existencias adicionales';

        if ($activo->tipo_control === TipoControlActivo::SeguimientoIndividual) {
            $unidades = $registrarUnidades->ejecutar(
                empresa: $activo->empresa,
                activo: $activo,
                almacen: Almacen::query()->findOrFail($request->integer('almacen_id')),
                cantidad: $request->integer('cantidad'),
                motivo: $motivo,
                realizadoPor: $request->user()?->id,
                especificaciones: array_values((array) $request->input('especificaciones', [])),
            );

            if ($request->boolean('abrir_etiquetas')) {
                return back()
                    ->with('toast', ['type' => 'success', 'message' => 'Unidades agregadas correctamente.'])
                    ->with('etiquetasUrl', route('unidades-activo.etiquetas', ['ids' => $unidades->pluck('id')->implode(',')]));
            }

            return back()->with('toast', ['type' => 'success', 'message' => 'Unidades agregadas.']);
        }

        $registrarEntrada->ejecutar(
            empresaId: $activo->empresa_id,
            almacenId: $request->integer('almacen_id'),
            items: [[
                'activo_id' => $activo->id,
                'talla_id' => $request->integer('talla_id') ?: null,
                'cantidad' => $request->integer('cantidad'),
            ]],
            motivo: $motivo,
            realizadoPor: $request->user()?->id,
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Existencias agregadas.']);
    }

    /**
     * Variantes / tallas activas del catálogo global, para que el formulario
     * las ofrezca sin importar la empresa del activo. Los tipos y las
     * categorías tampoco viajan aquí: el formulario los busca en vivo
     * (`/tipos-activo/buscar`, `/categorias-activo/buscar`).
     *
     * @return array<int, array{id: int, valor: string}>
     */
    private function tallasGlobales(): array
    {
        return Talla::query()->where('activa', true)->orderBy('orden')->orderBy('valor')
            ->get(['id', 'valor'])
            ->map(fn (Talla $t): array => ['id' => $t->id, 'valor' => $t->valor])
            ->all();
    }

    /**
     * Resuelve `categoria_id` y su espejo de texto `categoria`. Fuente de verdad:
     * `categoria_id`; `categoria` es espejo temporal.
     *
     * @return array{categoria_id: int|null, categoria: string|null}
     */
    private function datosCategoria(GuardarActivoRequest $request, int $empresaId): array
    {
        $categoriaId = $request->integer('categoria_id') ?: null;

        $nombre = $categoriaId === null
            ? null
            : CategoriaActivo::query()->whereKey($categoriaId)->value('nombre');

        return ['categoria_id' => $categoriaId, 'categoria' => $nombre];
    }

    /**
     * Traduce el formulario de alta a filas `{talla_id, cantidad}` listas
     * para `RegistrarEntradaInventario`. Sin variantes: una fila con
     * `talla_id = null` y `cantidad_inicial`. Con variantes: una fila por
     * cada entrada de `existencias` (las que no capturó el usuario quedan en
     * 0 y se descartan aguas abajo).
     *
     * @param  array<int, int>  $tallaIds
     * @return array<int, array{talla_id: int|null, cantidad: int}>
     */
    private function existenciaInicialDesdeRequest(GuardarActivoRequest $request, array $tallaIds): array
    {
        if ($tallaIds === []) {
            return [['talla_id' => null, 'cantidad' => (int) $request->input('cantidad_inicial', 0)]];
        }

        /** @var array<int, mixed> $entradaExistencias */
        $entradaExistencias = (array) $request->input('existencias', []);

        $existencias = collect($entradaExistencias)
            ->filter(fn ($f) => is_array($f) && isset($f['talla_id']))
            ->keyBy(fn (array $f): int => (int) $f['talla_id']);

        return collect($tallaIds)
            ->map(fn (int $tallaId): array => [
                'talla_id' => $tallaId,
                'cantidad' => (int) ($existencias->get($tallaId)['cantidad'] ?? 0),
            ])
            ->all();
    }

    /**
     * @return array{crear_tipo: bool, crear_categoria: bool, crear_variante: bool}
     */
    private function permisosCatalogos(Request $request): array
    {
        $usuario = $request->user();

        return [
            'crear_tipo' => $usuario?->can('administrar', TipoActivo::class) ?? false,
            'crear_categoria' => $usuario?->can('administrar', CategoriaActivo::class) ?? false,
            'crear_variante' => $usuario?->can('tallas.administrar') ?? false,
        ];
    }

    /**
     * Genera un código consecutivo y único dentro de la empresa (ACT-0001,
     * ACT-0002, …). Race-safe: `ServicioGeneradorCodigos` bloquea el
     * contador dentro de una transacción (nunca `count() + 1` sin lock) y
     * reconcilia contra el mayor código "ACT-XXXX" REALMENTE existente en
     * esa empresa en cada llamada — nunca repite un código ya usado aunque
     * el contador haya quedado atrasado.
     */
    private function generarCodigo(Empresa $empresa): string
    {
        return $this->codigos->siguienteConPrefijo($empresa, 'activo', 'ACT', semilla: fn (): int => $this->maximoSufijo(
            Activo::query()->where('empresa_id', $empresa->id)->where('codigo', 'like', 'ACT-%')->pluck('codigo'),
            'ACT-',
        ));
    }

    /**
     * Previsualización NO autoritativa del siguiente código de activo para
     * la empresa indicada — no reserva el consecutivo. El valor definitivo
     * se calcula de nuevo, atómicamente, en `store()`.
     */
    public function siguienteCodigo(Request $request): JsonResponse
    {
        $this->authorize('create', Activo::class);

        $empresa = $this->resolverEmpresa($request);

        $codigo = $this->codigos->siguienteConPrefijoAproximado($empresa, 'activo', 'ACT', semilla: fn (): int => $this->maximoSufijo(
            Activo::query()->where('empresa_id', $empresa->id)->where('codigo', 'like', 'ACT-%')->pluck('codigo'),
            'ACT-',
        ));

        return response()->json(['codigo' => $codigo]);
    }
}
