<?php

namespace App\Http\Controllers;

use App\Enums\TipoControlActivo;
use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Requests\Activos\GuardarActivoRequest;
use App\Models\Activo;
use App\Models\CategoriaActivo;
use App\Models\Empresa;
use App\Models\SaldoInventario;
use App\Models\Talla;
use App\Models\TipoActivo;
use App\Servicios\ServicioAuditoria;
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

    public function __construct(private readonly ServicioAuditoria $auditoria) {}

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
            'control' => ['nullable', Rule::in(['cantidad', 'serializado'])],
            'estado' => ['nullable', Rule::in(['activos', 'inactivos'])],
            'orden' => ['nullable', Rule::in(['az', 'za'])],
        ]);

        $orden = ($filtros['orden'] ?? 'az') === 'za' ? 'desc' : 'asc';

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
            ->when($filtros['control'] ?? null, fn (Builder $q, $v) => $q->where('tipo_control', $v))
            ->when(($filtros['estado'] ?? null) === 'activos', fn (Builder $q) => $q->where('activo', true))
            ->when(($filtros['estado'] ?? null) === 'inactivos', fn (Builder $q) => $q->where('activo', false))
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
                    ? TipoActivo::query()->whereIn('empresa_id', $idsScope->all())
                        ->whereKey($filtros['tipo_activo_id'])->first(['id', 'nombre'])
                    : null,
                'categoria' => ($filtros['categoria_id'] ?? null)
                    ? CategoriaActivo::query()->whereIn('empresa_id', $idsScope->all())
                        ->whereKey($filtros['categoria_id'])->first(['id', 'nombre', 'tipo_activo_id'])
                    : null,
            ],
            'filtros' => [
                'buscar' => $filtros['buscar'] ?? '',
                'empresa_id' => $empresaFiltro?->id,
                'tipo_activo_id' => $filtros['tipo_activo_id'] ?? '',
                'categoria_id' => $filtros['categoria_id'] ?? '',
                'control' => $filtros['control'] ?? '',
                'estado' => $filtros['estado'] ?? '',
                'orden' => $filtros['orden'] ?? 'az',
            ],
            'permisos' => [
                'crear' => $request->user()->can('create', Activo::class),
                'editar' => $request->user()->can('activos.editar'),
                'administrar' => $request->user()->can('activos.administrar'),
                'administrar_catalogos' => $request->user()->can('administrar', TipoActivo::class),
            ],
        ]);
    }

    /**
     * Búsqueda con autocompletado para los combobox de activos. Requiere
     * `empresa_id`: el activo pertenece a una empresa concreta.
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

        $activos = Activo::query()
            ->where('empresa_id', $empresa->id)
            ->where('activo', true)
            ->when(in_array($control, ['cantidad', 'serializado'], true), fn (Builder $q) => $q->where('tipo_control', $control))
            ->with(['tallas:id,valor', 'tipoActivo:id,nombre', 'categoriaActivo:id,nombre'])
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
            ->map(fn (Activo $a): array => [
                'id' => $a->id,
                'nombre' => $a->nombre,
                'codigo' => $a->codigo,
                'tipo' => $a->tipoActivo?->nombre,
                'categoria' => $a->categoriaActivo?->nombre,
                'control' => $a->tipo_control->value,
                'tallas' => $a->tallas->map(fn (Talla $t): array => ['id' => $t->id, 'valor' => $t->valor])->values(),
            ]);

        return response()->json(['activos' => $activos]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Activo::class);

        return Inertia::render('Activos/Formulario', [
            'activo' => null,
            'seleccion' => ['tipo' => null, 'categoria' => null],
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
            'catalogosPorEmpresa' => $this->catalogosPorEmpresa($request),
            'tiposControl' => TipoControlActivo::opciones(),
            'permisos' => $this->permisosCatalogos($request),
        ]);
    }

    public function store(GuardarActivoRequest $request): RedirectResponse
    {
        $empresa = $request->empresaResuelta();

        $activo = Activo::query()->create([
            'empresa_id' => $empresa->id,
            'tipo_activo_id' => $request->integer('tipo_activo_id') ?: null,
            ...$this->datosCategoria($request, $empresa->id),
            'nombre' => $request->string('nombre'),
            'descripcion' => $request->input('descripcion'),
            'tipo_control' => (string) $request->string('tipo_control'),
            'codigo' => ($request->input('codigo') ?: null) ?: $this->generarCodigo($empresa->id),
            'activo' => $request->boolean('activo', true),
            'imagen_ruta' => $request->hasFile('imagen')
                ? ($request->file('imagen')->store("activos/{$empresa->id}", 'public') ?: null)
                : null,
        ]);

        $activo->tallas()->sync($request->input('tallas', []));

        $this->auditoria->registrar('activos', 'crear', [
            'tipo_entidad' => Activo::class, 'entidad_id' => $activo->id, 'empresa_id' => $empresa->id,
            'descripcion' => 'Alta de activo '.$activo->nombre,
        ]);

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
            'catalogosPorEmpresa' => $this->catalogosPorEmpresa($request, [$activo->empresa_id]),
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
            'codigo' => $request->input('codigo') ?: $activo->codigo,
            'activo' => $request->boolean('activo', $activo->activo),
        ]);

        if ($request->hasFile('imagen')) {
            if ($activo->imagen_ruta) {
                Storage::disk('public')->delete($activo->imagen_ruta);
            }
            $activo->imagen_ruta = $request->file('imagen')->store("activos/{$activo->empresa_id}", 'public') ?: null;
        }

        $activo->save();
        $activo->tallas()->sync($request->input('tallas', []));

        $this->auditoria->registrar('activos', 'editar', [
            'tipo_entidad' => Activo::class, 'entidad_id' => $activo->id, 'empresa_id' => $activo->empresa_id,
            'descripcion' => 'Edición de activo '.$activo->nombre,
        ]);

        return to_route('activos.index')->with('toast', ['type' => 'success', 'message' => 'Activo actualizado.']);
    }

    public function toggle(Activo $activo): RedirectResponse
    {
        $this->authorize('administrar', $activo);

        $activo->update(['activo' => ! $activo->activo]);

        $this->auditoria->registrar('activos', $activo->activo ? 'activar' : 'desactivar', [
            'tipo_entidad' => Activo::class, 'entidad_id' => $activo->id, 'empresa_id' => $activo->empresa_id,
            'descripcion' => ($activo->activo ? 'Activación' : 'Desactivación').' de activo '.$activo->nombre,
        ]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $activo->activo ? 'Activo activado.' : 'Activo desactivado.',
        ]);
    }

    public function show(Request $request, Activo $activo): Response
    {
        $this->authorize('view', $activo);

        $activo->load('tipoActivo:id,nombre', 'empresa:id,nombre_comercial');

        $saldos = SaldoInventario::query()
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

        return Inertia::render('Activos/Detalle', [
            'activo' => [
                ...$activo->only(['id', 'nombre', 'descripcion', 'codigo', 'categoria', 'activo']),
                'empresa' => ['id' => $activo->empresa_id, 'nombre_comercial' => $activo->empresa?->nombre_comercial],
                'tipo' => $activo->tipoActivo?->nombre,
                'tipo_control' => $activo->tipo_control->value,
                'tipo_control_etiqueta' => $activo->tipo_control->etiqueta(),
                'imagen_url' => $activo->imagen_ruta ? Storage::disk('public')->url($activo->imagen_ruta) : null,
                'tallas' => $activo->tallas()->pluck('valor'),
            ],
            'saldos' => $saldos,
            'permisos' => [
                'editar' => $request->user()->can('update', $activo),
                'administrar' => $request->user()->can('administrar', $activo),
            ],
        ]);
    }

    /**
     * Variantes / tallas seleccionables de cada empresa autorizada, para que el
     * formulario las muestre según la empresa elegida. Los tipos y las categorías
     * ya no viajan aquí: el formulario los busca en vivo (`/tipos-activo/buscar`,
     * `/categorias-activo/buscar`) con la empresa como dependencia.
     *
     * @param  array<int, int>  $soloEmpresas  restringe a estos ids (edición)
     * @return array<int, array{tallas: mixed}>
     */
    private function catalogosPorEmpresa(Request $request, array $soloEmpresas = []): array
    {
        $empresas = $this->empresasAutorizadas($request)
            ->when($soloEmpresas !== [], fn ($c) => $c->whereIn('id', $soloEmpresas));

        return $empresas->mapWithKeys(fn (Empresa $e): array => [$e->id => [
            'tallas' => $e->tallas()->seleccionables()->ordenadas()->get(['id', 'valor']),
        ]])->all();
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
            : CategoriaActivo::query()
                ->where('empresa_id', $empresaId)
                ->whereKey($categoriaId)
                ->value('nombre');

        return ['categoria_id' => $categoriaId, 'categoria' => $nombre];
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
     * Genera un código consecutivo y único dentro de la empresa
     * (ACT-0001, ACT-0002, …) cuando el usuario no captura uno.
     */
    private function generarCodigo(int $empresaId): string
    {
        $n = Activo::query()->withTrashed()->where('empresa_id', $empresaId)->count() + 1;

        do {
            $codigo = 'ACT-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            $n++;
        } while (Activo::query()->withTrashed()->where('empresa_id', $empresaId)->where('codigo', $codigo)->exists());

        return $codigo;
    }
}
