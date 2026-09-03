<?php

namespace App\Http\Controllers;

use App\Enums\TipoControlActivo;
use App\Http\Controllers\Concerns\ConEmpresaActiva;
use App\Http\Requests\Activos\GuardarActivoRequest;
use App\Models\Activo;
use App\Models\CategoriaActivo;
use App\Models\SaldoInventario;
use App\Models\TipoActivo;
use App\Servicios\ServicioAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ActivoController extends Controller
{
    use ConEmpresaActiva;

    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Activo::class);
        $empresa = $this->empresaActiva();

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
            ->where('empresa_id', $empresa->id)
            ->selectRaw('activo_id, SUM(cantidad) as total, SUM(CASE WHEN minimo > 0 AND cantidad <= minimo THEN 1 ELSE 0 END) as tallas_bajo_minimo')
            ->groupBy('activo_id')
            ->get()
            ->keyBy('activo_id');

        $activos = Activo::query()
            ->where('empresa_id', $empresa->id)
            ->with(['tallas:id,valor', 'tipoActivo:id,nombre'])
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
            'tiposActivo' => $this->tiposActivoEmpresa(),
            'categorias' => $this->categoriasEmpresa(),
            'filtros' => [
                'buscar' => $filtros['buscar'] ?? '',
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

    public function create(): Response
    {
        $this->authorize('create', Activo::class);

        return Inertia::render('Activos/Formulario', [
            'activo' => null,
            'tallas' => $this->tallasEmpresa(),
            'tiposActivo' => $this->tiposActivoEmpresa(),
            'categorias' => $this->categoriasEmpresa(),
            'tiposControl' => TipoControlActivo::opciones(),
            'permisos' => $this->permisosCatalogos(),
        ]);
    }

    public function store(GuardarActivoRequest $request): RedirectResponse
    {
        $empresa = $this->empresaActiva();

        $activo = Activo::query()->create([
            'empresa_id' => $empresa->id,
            'tipo_activo_id' => $request->integer('tipo_activo_id') ?: null,
            ...$this->datosCategoria($request),
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
            'tipo_entidad' => Activo::class,
            'entidad_id' => $activo->id,
            'descripcion' => 'Alta de activo '.$activo->nombre,
        ]);

        return to_route('activos.index')->with('toast', ['type' => 'success', 'message' => 'Activo creado.']);
    }

    public function edit(Activo $activo): Response
    {
        $this->authorize('update', $activo);
        $this->verificarEmpresa($activo);

        return Inertia::render('Activos/Formulario', [
            'activo' => [
                ...$activo->only(['id', 'nombre', 'descripcion', 'codigo', 'tipo_activo_id', 'categoria_id', 'activo']),
                'tipo_control' => $activo->tipo_control->value,
                'imagen_url' => $activo->imagen_ruta ? Storage::disk('public')->url($activo->imagen_ruta) : null,
                'tallas' => $activo->tallas()->pluck('tallas.id'),
            ],
            'tallas' => $this->tallasEmpresa(),
            'tiposActivo' => $this->tiposActivoEmpresa(),
            'categorias' => $this->categoriasEmpresa(),
            'tiposControl' => TipoControlActivo::opciones(),
            'permisos' => $this->permisosCatalogos(),
        ]);
    }

    public function update(GuardarActivoRequest $request, Activo $activo): RedirectResponse
    {
        $this->verificarEmpresa($activo);
        $empresa = $this->empresaActiva();

        $activo->fill([
            'tipo_activo_id' => $request->integer('tipo_activo_id') ?: null,
            ...$this->datosCategoria($request),
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
            $activo->imagen_ruta = $request->file('imagen')->store("activos/{$empresa->id}", 'public') ?: null;
        }

        $activo->save();
        $activo->tallas()->sync($request->input('tallas', []));

        $this->auditoria->registrar('activos', 'editar', [
            'tipo_entidad' => Activo::class,
            'entidad_id' => $activo->id,
            'descripcion' => 'Edición de activo '.$activo->nombre,
        ]);

        return to_route('activos.index')->with('toast', ['type' => 'success', 'message' => 'Activo actualizado.']);
    }

    public function toggle(Activo $activo): RedirectResponse
    {
        $this->authorize('administrar', $activo);
        $this->verificarEmpresa($activo);

        $activo->update(['activo' => ! $activo->activo]);

        $this->auditoria->registrar('activos', $activo->activo ? 'activar' : 'desactivar', [
            'tipo_entidad' => Activo::class,
            'entidad_id' => $activo->id,
            'descripcion' => ($activo->activo ? 'Activación' : 'Desactivación').' de activo '.$activo->nombre,
        ]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $activo->activo ? 'Activo activado.' : 'Activo desactivado.',
        ]);
    }

    public function show(Activo $activo): Response
    {
        $this->authorize('view', $activo);
        $this->verificarEmpresa($activo);

        $activo->load('tipoActivo:id,nombre');

        $saldos = SaldoInventario::query()
            ->where('empresa_id', $activo->empresa_id)
            ->where('activo_id', $activo->id)
            ->whereNotNull('almacen_id')
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
                'tipo' => $activo->tipoActivo?->nombre,
                'tipo_control' => $activo->tipo_control->value,
                'tipo_control_etiqueta' => $activo->tipo_control->etiqueta(),
                'imagen_url' => $activo->imagen_ruta ? Storage::disk('public')->url($activo->imagen_ruta) : null,
                'tallas' => $activo->tallas()->pluck('valor'),
            ],
            'saldos' => $saldos,
            'permisos' => [
                'editar' => request()->user()->can('update', $activo),
                'administrar' => request()->user()->can('administrar', $activo),
            ],
        ]);
    }

    /**
     * @return array<int, array{id: int, valor: string}>
     */
    private function tallasEmpresa(): array
    {
        return $this->empresaActiva()->tallas()->ordenadas()->get(['id', 'valor'])->toArray();
    }

    /**
     * @return array<int, array{id: int, nombre: string}>
     */
    private function tiposActivoEmpresa(): array
    {
        return TipoActivo::query()
            ->where('empresa_id', $this->empresaActiva()->id)
            ->where('activo', true)
            ->orderBy('nombre')
            ->get(['id', 'nombre'])
            ->map(fn (TipoActivo $t): array => ['id' => $t->id, 'nombre' => $t->nombre])
            ->all();
    }

    /**
     * @return array<int, array{id: int, nombre: string}>
     */
    private function categoriasEmpresa(): array
    {
        return CategoriaActivo::query()
            ->where('empresa_id', $this->empresaActiva()->id)
            ->where('activa', true)
            ->orderBy('nombre')
            ->get(['id', 'nombre'])
            ->map(fn (CategoriaActivo $c): array => ['id' => $c->id, 'nombre' => $c->nombre])
            ->all();
    }

    /**
     * Resuelve `categoria_id` y su espejo de texto `categoria` a partir de la
     * petición. La fuente de verdad es `categoria_id`; `categoria` se conserva
     * como espejo temporal (importador/exportador/snapshot).
     *
     * @return array{categoria_id: int|null, categoria: string|null}
     */
    private function datosCategoria(GuardarActivoRequest $request): array
    {
        $categoriaId = $request->integer('categoria_id') ?: null;

        $nombre = $categoriaId === null
            ? null
            : CategoriaActivo::query()
                ->where('empresa_id', $this->empresaActiva()->id)
                ->whereKey($categoriaId)
                ->value('nombre');

        return ['categoria_id' => $categoriaId, 'categoria' => $nombre];
    }

    /**
     * @return array{crear_tipo: bool, crear_categoria: bool}
     */
    private function permisosCatalogos(): array
    {
        $usuario = request()->user();

        return [
            'crear_tipo' => $usuario?->can('administrar', TipoActivo::class) ?? false,
            'crear_categoria' => $usuario?->can('administrar', CategoriaActivo::class) ?? false,
        ];
    }

    private function verificarEmpresa(Activo $activo): void
    {
        abort_unless($activo->empresa_id === $this->empresaActiva()->id, 404);
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
