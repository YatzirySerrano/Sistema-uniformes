<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Controllers\Concerns\CreaConCodigoUnico;
use App\Http\Controllers\Concerns\ExportaListado;
use App\Http\Controllers\Concerns\ReconciliaSecuenciaCodigo;
use App\Http\Requests\Conjuntos\GuardarConjuntoRequest;
use App\Models\Almacen;
use App\Models\Conjunto;
use App\Models\ConjuntoComponente;
use App\Models\Empresa;
use App\Servicios\ServicioAuditoria;
use App\Soporte\ContextoExportacion;
use App\Soporte\ServicioGeneradorCodigos;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Conjuntos: agrupaciones lógicas de Activos de UNA empresa. Plantilla sin
 * stock propio; la disponibilidad se calcula en vivo (`Conjunto::disponibilidad()`).
 */
class ConjuntoController extends Controller
{
    use ConEmpresa;
    use CreaConCodigoUnico;
    use ExportaListado;
    use ReconciliaSecuenciaCodigo;

    public function __construct(
        private readonly ServicioAuditoria $auditoria,
        private readonly ServicioGeneradorCodigos $codigos,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Conjunto::class);

        $empresaFiltro = $this->empresaDelFiltro($request);
        $filtros = $this->filtrosListado($request);

        $conjuntos = $this->consultaConjuntos($request, $filtros)
            ->get()
            ->map(fn (Conjunto $c): array => [
                'id' => $c->id,
                'nombre' => $c->nombre,
                'codigo' => $c->codigo,
                'descripcion' => $c->descripcion,
                'activo' => $c->activo,
                'empresa' => ['id' => $c->empresa_id, 'nombre_comercial' => $c->empresa?->nombre_comercial],
                'componentes_count' => (int) $c->componentes_count,
            ]);

        return Inertia::render('Conjuntos/Index', [
            'conjuntos' => $conjuntos,
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
            'filtros' => [
                'buscar' => $filtros['buscar'] ?? '',
                'empresa_id' => $empresaFiltro?->id,
                'estado' => $filtros['estado'] ?? '',
            ],
            'permisos' => [
                'crear' => $request->user()->can('create', Conjunto::class),
                'editar' => $request->user()->can('conjuntos.editar'),
                'administrar' => $request->user()->can('conjuntos.administrar'),
                'verEliminados' => $request->user()->can('conjuntos.administrar'),
            ],
        ]);
    }

    /**
     * Excel/PDF del listado, respetando los mismos filtros que `index()`.
     */
    public function exportar(Request $request): BinaryFileResponse|HttpResponse
    {
        $this->authorize('viewAny', Conjunto::class);

        $filtros = $this->filtrosListado($request);
        $empresaFiltro = $this->empresaDelFiltro($request);
        $conjuntos = $this->consultaConjuntos($request, $filtros)->get();

        $filas = $conjuntos->map(fn (Conjunto $c): array => [
            $c->nombre,
            $c->codigo,
            $c->descripcion,
            $c->activo ? 'Activo' : 'Inactivo',
            $c->empresa?->nombre_comercial,
            (int) $c->componentes_count,
        ])->all();

        $filtrosHumanos = array_filter([
            'Búsqueda' => $filtros['buscar'] ?? null,
            'Estado' => match ($filtros['estado'] ?? null) {
                'activos' => 'Activos',
                'inactivos' => 'Eliminados',
                default => null,
            },
        ]);

        $contexto = new ContextoExportacion('Conjuntos', $empresaFiltro, $filtrosHumanos, $conjuntos->count());

        return $this->respuestaExportacion($request->input('formato', 'xlsx'), $filas, [
            'Nombre', 'Código', 'Descripción', 'Estado', 'Empresa', 'Componentes',
        ], $contexto);
    }

    /**
     * @return array<string, mixed>
     */
    private function filtrosListado(Request $request): array
    {
        return $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', Rule::in(['activos', 'inactivos'])],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Builder<Conjunto>
     */
    private function consultaConjuntos(Request $request, array $filtros): Builder
    {
        $idsAutorizadas = $this->idsEmpresasAutorizadas($request);
        $empresaFiltro = $this->empresaDelFiltro($request);
        $idsScope = $empresaFiltro !== null ? collect([$empresaFiltro->id]) : $idsAutorizadas;

        // Sólo quien puede administrar conjuntos puede verlos eliminados en
        // el listado. Para el resto, "activo" se fuerza sin importar qué
        // `estado` pida la URL.
        $puedeVerEliminados = $request->user()->can('conjuntos.administrar');

        return Conjunto::query()
            ->whereIn('empresa_id', $idsScope)
            ->withCount('componentes')
            ->with('empresa:id,nombre_comercial')
            ->when($filtros['buscar'] ?? null, fn (Builder $q, string $b) => $q->where(fn (Builder $s) => $s->where('nombre', 'like', "%{$b}%")->orWhere('codigo', 'like', "%{$b}%")))
            ->when(! $puedeVerEliminados, fn (Builder $q) => $q->where('activo', true))
            ->when($puedeVerEliminados && ($filtros['estado'] ?? null) === 'activos', fn (Builder $q) => $q->where('activo', true))
            ->when($puedeVerEliminados && ($filtros['estado'] ?? null) === 'inactivos', fn (Builder $q) => $q->where('activo', false))
            ->orderBy('nombre');
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Conjunto::class);

        return Inertia::render('Conjuntos/Formulario', [
            'conjunto' => null,
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
        ]);
    }

    public function store(GuardarConjuntoRequest $request): RedirectResponse
    {
        $empresa = $request->empresaResuelta();

        $conjunto = $this->crearConCodigoUnico(fn () => Conjunto::query()->create([
            'empresa_id' => $empresa->id,
            'nombre' => $request->string('nombre'),
            'descripcion' => $request->input('descripcion'),
            'codigo' => $this->generarCodigo($empresa),
            'activo' => $request->boolean('activo', true),
        ]));

        $this->sincronizarComponentes($conjunto, $request->input('componentes', []));

        $this->auditoria->registrar('activos', 'conjunto_crear', [
            'tipo_entidad' => Conjunto::class, 'entidad_id' => $conjunto->id, 'empresa_id' => $empresa->id,
            'descripcion' => 'Alta de conjunto '.$conjunto->nombre,
        ]);

        return to_route('conjuntos.index')->with('toast', ['type' => 'success', 'message' => 'Conjunto creado.']);
    }

    public function edit(Request $request, Conjunto $conjunto): Response
    {
        $this->authorize('update', $conjunto);

        $conjunto->load(['componentes.activo:id,nombre,codigo', 'componentes.talla:id,valor']);

        return Inertia::render('Conjuntos/Formulario', [
            'conjunto' => [
                ...$conjunto->only(['id', 'nombre', 'codigo', 'descripcion', 'activo', 'empresa_id']),
                'componentes' => $conjunto->componentes->map(fn (ConjuntoComponente $c): array => [
                    'activo_id' => $c->activo_id,
                    'activo_nombre' => $c->activo?->nombre,
                    'activo_codigo' => $c->activo?->codigo,
                    'cantidad_requerida' => $c->cantidad_requerida,
                    'talla_id' => $c->talla_id,
                    'talla_valor' => $c->talla?->valor,
                    'talla_libre' => $c->talla_libre,
                ]),
            ],
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
        ]);
    }

    public function update(GuardarConjuntoRequest $request, Conjunto $conjunto): RedirectResponse
    {
        // El código es autogenerado e inmutable: se asignó al crear el
        // conjunto y nunca se reescribe (aunque un request manipulado envíe
        // `codigo`).
        $conjunto->update([
            'nombre' => $request->string('nombre'),
            'descripcion' => $request->input('descripcion'),
            'activo' => $request->boolean('activo', $conjunto->activo),
        ]);

        $this->sincronizarComponentes($conjunto, $request->input('componentes', []));

        $this->auditoria->registrar('activos', 'conjunto_editar', [
            'tipo_entidad' => Conjunto::class, 'entidad_id' => $conjunto->id, 'empresa_id' => $conjunto->empresa_id,
            'descripcion' => 'Edición de conjunto '.$conjunto->nombre,
        ]);

        return to_route('conjuntos.index')->with('toast', ['type' => 'success', 'message' => 'Conjunto actualizado.']);
    }

    public function toggle(Conjunto $conjunto): RedirectResponse
    {
        $this->authorize('administrar', $conjunto);

        $conjunto->update(['activo' => ! $conjunto->activo]);

        $this->auditoria->registrar('activos', $conjunto->activo ? 'conjunto_activar' : 'conjunto_desactivar', [
            'tipo_entidad' => Conjunto::class, 'entidad_id' => $conjunto->id, 'empresa_id' => $conjunto->empresa_id,
            'descripcion' => ($conjunto->activo ? 'Activación' : 'Desactivación').' de conjunto '.$conjunto->nombre,
            'valores_anteriores' => ['activo' => ! $conjunto->activo],
            'valores_nuevos' => ['activo' => $conjunto->activo],
        ]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $conjunto->activo ? 'Conjunto restaurado.' : 'Conjunto eliminado.',
        ]);
    }

    public function show(Request $request, Conjunto $conjunto): Response
    {
        $this->authorize('view', $conjunto);

        $conjunto->load(['componentes.activo:id,nombre,codigo,tipo_control', 'componentes.talla:id,valor']);

        $almacenes = Almacen::query()->activos()->paraEmpresa($conjunto->empresa_id)->get(['id', 'nombre']);

        return Inertia::render('Conjuntos/Detalle', [
            'conjunto' => [
                ...$conjunto->only(['id', 'nombre', 'codigo', 'descripcion', 'activo']),
                'empresa' => ['id' => $conjunto->empresa_id, 'nombre_comercial' => $conjunto->empresa?->nombre_comercial],
                'componentes' => $conjunto->componentes->map(fn (ConjuntoComponente $c): array => [
                    'activo' => $c->activo?->nombre,
                    'codigo' => $c->activo?->codigo,
                    'control' => $c->activo?->tipo_control->value,
                    'cantidad_requerida' => $c->cantidad_requerida,
                    'talla' => $c->talla?->valor,
                    'talla_libre' => $c->talla_libre,
                ]),
            ],
            'almacenes' => $almacenes->map(fn (Almacen $a): array => [
                'id' => $a->id,
                'nombre' => $a->nombre,
                'disponibilidad' => $conjunto->disponibilidad($a->id),
            ]),
            'permisos' => [
                'editar' => $request->user()->can('update', $conjunto),
                'administrar' => $request->user()->can('administrar', $conjunto),
            ],
        ]);
    }

    /**
     * Búsqueda con autocompletado para el combobox de conjuntos (flujo de
     * Entregas). Requiere `empresa_id`. Incluye, por conjunto, los componentes
     * de variante LIBRE con sus tallas elegibles: el formulario de Entrega los
     * necesita para pedir la variante concreta de cada componente libre.
     */
    public function buscar(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Conjunto::class);

        $empresa = $this->empresaDelFiltro($request);

        if ($empresa === null) {
            return response()->json(['conjuntos' => []]);
        }

        $termino = trim((string) $request->query('q', ''));
        $almacenId = $request->filled('almacen_id') ? (int) $request->query('almacen_id') : null;

        $conjuntos = Conjunto::query()
            ->where('empresa_id', $empresa->id)
            ->where('activo', true)
            ->when($termino !== '', fn (Builder $q) => $q->where('nombre', 'like', "%{$termino}%"))
            ->with(['componentes' => fn ($q) => $q->where('talla_libre', true)->with('activo:id,nombre')])
            ->orderBy('nombre')
            ->limit(20)
            ->get();

        $resultado = [];

        foreach ($conjuntos as $conjunto) {
            $componentesVarianteLibre = [];

            foreach ($conjunto->componentes as $comp) {
                /** @var ConjuntoComponente $comp */
                $tallas = [];
                foreach ($comp->activo?->tallasElegibles() ?? [] as $talla) {
                    $tallas[] = ['id' => $talla->id, 'valor' => $talla->valor];
                }

                $componentesVarianteLibre[] = [
                    'componente_id' => $comp->id,
                    'activo_id' => $comp->activo_id,
                    'activo_nombre' => $comp->activo?->nombre,
                    'tallas' => $tallas,
                ];
            }

            $resultado[] = [
                'id' => $conjunto->id,
                'nombre' => $conjunto->nombre,
                'codigo' => $conjunto->codigo,
                'componentes_variante_libre' => $componentesVarianteLibre,
                'disponible' => $almacenId === null ? null : $conjunto->disponibilidad($almacenId),
            ];
        }

        return response()->json(['conjuntos' => $resultado]);
    }

    /**
     * Genera un código consecutivo y único dentro de la empresa (CON-0001,
     * CON-0002, …), con la misma filosofía que Sucursal/Área/Activo. Race-safe:
     * `ServicioGeneradorCodigos` bloquea el contador dentro de una transacción
     * y reconcilia contra el mayor código "CON-XXXX" REALMENTE existente en esa
     * empresa (incluidos los conjuntos eliminados por soft-delete, que siguen
     * ocupando su código) — nunca repite un código ya usado aunque el contador
     * haya quedado atrasado. Nunca lo captura el usuario.
     */
    private function generarCodigo(Empresa $empresa): string
    {
        return $this->codigos->siguienteConPrefijo($empresa, 'conjunto', 'CON', semilla: fn (): int => $this->maximoSufijo(
            Conjunto::withTrashed()->where('empresa_id', $empresa->id)->where('codigo', 'like', 'CON-%')->pluck('codigo'),
            'CON-',
        ));
    }

    /**
     * Previsualización NO autoritativa del siguiente código de conjunto para la
     * empresa indicada — no reserva el consecutivo. El valor definitivo se
     * calcula de nuevo, atómicamente, en `store()`.
     */
    public function siguienteCodigo(Request $request): JsonResponse
    {
        $this->authorize('create', Conjunto::class);

        $empresa = $this->resolverEmpresa($request);

        $codigo = $this->codigos->siguienteConPrefijoAproximado($empresa, 'conjunto', 'CON', semilla: fn (): int => $this->maximoSufijo(
            Conjunto::withTrashed()->where('empresa_id', $empresa->id)->where('codigo', 'like', 'CON-%')->pluck('codigo'),
            'CON-',
        ));

        return response()->json(['codigo' => $codigo]);
    }

    /**
     * @param  array<int, array{activo_id: int, cantidad_requerida: int, talla_id?: int|null, talla_libre?: bool}>  $componentes
     */
    private function sincronizarComponentes(Conjunto $conjunto, array $componentes): void
    {
        $conjunto->componentes()->delete();

        foreach ($componentes as $fila) {
            $conjunto->componentes()->create([
                'activo_id' => (int) $fila['activo_id'],
                'cantidad_requerida' => (int) $fila['cantidad_requerida'],
                'talla_id' => ($fila['talla_id'] ?? null) ?: null,
                'talla_libre' => filter_var($fila['talla_libre'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ]);
        }
    }
}
