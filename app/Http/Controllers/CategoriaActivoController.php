<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Requests\Activos\GuardarCategoriaActivoRequest;
use App\Models\CategoriaActivo;
use App\Models\Empresa;
use App\Servicios\ServicioAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * CRUD del catálogo COMPARTIDO de categorías de activo. No pertenece a una
 * empresa: `activa` es su estado global y `categoria_activo_empresa` la habilita
 * por empresa. Puede relacionarse (opcionalmente) con un tipo del catálogo
 * compartido. Sin borrado físico.
 */
class CategoriaActivoController extends Controller
{
    use ConEmpresa;

    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    public function store(GuardarCategoriaActivoRequest $request): RedirectResponse
    {
        $empresaIds = $request->collect('empresa_ids')->map(fn ($id): int => (int) $id)->all();

        $this->crear(
            $request->validated('nombre'),
            $request->integer('tipo_activo_id') ?: null,
            $request->boolean('activa', true),
            $empresaIds,
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Categoría creada.']);
    }

    public function update(GuardarCategoriaActivoRequest $request, CategoriaActivo $categoria): RedirectResponse
    {
        $categoria->update([
            'nombre' => $request->validated('nombre'),
            'tipo_activo_id' => $request->integer('tipo_activo_id') ?: null,
            'activa' => $request->boolean('activa', $categoria->activa),
        ]);

        $this->auditar('categoria_editar', $categoria, 'Edición de categoría '.$categoria->nombre);

        return back()->with('toast', ['type' => 'success', 'message' => 'Categoría actualizada.']);
    }

    public function toggle(CategoriaActivo $categoria): RedirectResponse
    {
        $this->authorize('administrar', $categoria);

        $categoria->update(['activa' => ! $categoria->activa]);

        $this->auditar(
            $categoria->activa ? 'categoria_activar' : 'categoria_desactivar',
            $categoria,
            ($categoria->activa ? 'Activación' : 'Desactivación').' global de categoría '.$categoria->nombre,
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => $categoria->activa ? 'Categoría activada.' : 'Categoría desactivada.',
        ]);
    }

    /**
     * Sincroniza (bulk) las empresas para las que la categoría está habilitada —
     * diálogo "Empresas".
     */
    public function empresas(Request $request, CategoriaActivo $categoria): RedirectResponse
    {
        $this->authorize('administrar', $categoria);

        $idsAutorizadas = $this->idsEmpresasAutorizadas($request);

        $datos = $request->validate([
            'empresa_ids' => ['present', 'array'],
            'empresa_ids.*' => ['integer', Rule::in($idsAutorizadas->all())],
        ]);

        $fuera = $categoria->empresas()->whereNotIn('empresas.id', $idsAutorizadas)->pluck('empresas.id');
        $categoria->empresas()->sync($fuera->merge($datos['empresa_ids'])->unique()->all());

        $this->auditar('categoria_empresas', $categoria, 'Actualización de empresas habilitadas para la categoría '.$categoria->nombre);

        $n = count($datos['empresa_ids']);

        return back()->with('toast', ['type' => 'success', 'message' => "Categoría «{$categoria->nombre}»: {$n} ".($n === 1 ? 'empresa habilitada.' : 'empresas habilitadas.')]);
    }

    /**
     * Habilita / deshabilita la categoría para UNA empresa (toggle desde el
     * listado). El mensaje nombra la empresa afectada.
     */
    public function empresa(Request $request, CategoriaActivo $categoria): RedirectResponse
    {
        $this->authorize('administrar', $categoria);

        $datos = $request->validate(['empresa_id' => ['required', 'integer']]);
        $empresa = Empresa::query()->findOrFail((int) $datos['empresa_id']);
        abort_unless($request->user()->puedeAccederEmpresa($empresa), 403);

        $estaba = $categoria->empresas()->whereKey($empresa->id)->exists();
        $estaba ? $categoria->empresas()->detach($empresa->id) : $categoria->empresas()->attach($empresa->id);

        $this->auditar(
            'categoria_empresas',
            $categoria,
            ($estaba ? 'Deshabilitación' : 'Habilitación')." de la categoría {$categoria->nombre} para {$empresa->nombre_comercial}",
        );

        $mensaje = $estaba
            ? "Categoría «{$categoria->nombre}» deshabilitada para «{$empresa->nombre_comercial}». Sigue disponible globalmente y en las demás empresas donde esté habilitada."
            : "Categoría «{$categoria->nombre}» habilitada para «{$empresa->nombre_comercial}».";

        return back()->with('toast', ['type' => 'success', 'message' => $mensaje]);
    }

    /**
     * Búsqueda con autocompletado para el combobox de categoría (formulario de
     * Activo y filtro del listado). Sólo categorías con estado global activo; si
     * llega `empresa_id` se acota a las habilitadas para esa empresa (inválida /
     * sin acceso → lista vacía), si no, a las habilitadas para alguna empresa
     * autorizada.
     *
     * Si llega `tipo_activo_id`, se priorizan y acotan las categorías de ese
     * tipo más las que no tienen tipo (una categoría de OTRO tipo no se ofrece;
     * las sin tipo siempre sí).
     */
    public function buscar(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CategoriaActivo::class);

        $termino = trim((string) $request->query('q', ''));
        $tipoActivoId = (int) $request->query('tipo_activo_id', 0);

        $consulta = CategoriaActivo::query()
            ->where('activa', true)
            ->with('tipoActivo:id,nombre');

        if ($request->filled('empresa_id')) {
            $empresa = $this->empresaDelFiltro($request);

            if ($empresa === null) {
                return response()->json(['categorias' => []]);
            }

            $consulta->paraEmpresa($empresa->id);
        } else {
            $consulta->whereHas('empresas', fn (Builder $q) => $q->whereIn('empresas.id', $this->idsEmpresasAutorizadas($request)));
        }

        if ($tipoActivoId > 0) {
            $consulta
                ->where(fn (Builder $q) => $q->where('tipo_activo_id', $tipoActivoId)->orWhereNull('tipo_activo_id'))
                ->orderByRaw('CASE WHEN tipo_activo_id = ? THEN 0 ELSE 1 END', [$tipoActivoId]);
        }

        $categorias = $consulta
            ->when($termino !== '', fn (Builder $q) => $q->where('nombre', 'like', "%{$termino}%"))
            ->orderBy('nombre')
            ->limit(20)
            ->get(['id', 'nombre', 'tipo_activo_id'])
            ->map(fn (CategoriaActivo $c): array => [
                'id' => $c->id,
                'nombre' => $c->nombre,
                'tipo_activo_id' => $c->tipo_activo_id,
                'tipo' => $c->tipoActivo?->nombre,
            ]);

        return response()->json(['categorias' => $categorias]);
    }

    /**
     * Alta rápida desde el combobox del formulario de Activo: crea la categoría
     * y la habilita SÓLO para la empresa del formulario.
     */
    public function rapido(Request $request): JsonResponse
    {
        $this->authorize('administrar', CategoriaActivo::class);
        $empresa = $this->resolverEmpresa($request);

        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'tipo_activo_id' => ['nullable', 'integer', Rule::exists('tipos_activo', 'id')],
        ], [
            'nombre.required' => 'Escribe el nombre de la nueva categoría.',
            'tipo_activo_id.exists' => 'El tipo de activo seleccionado no existe.',
        ]);

        $nombre = trim($datos['nombre']);

        if (CategoriaActivo::existeNombre($nombre)) {
            throw ValidationException::withMessages([
                'nombre' => 'Ya existe una categoría con ese nombre.',
            ]);
        }

        $categoria = $this->crear($nombre, $datos['tipo_activo_id'] ?? null, true, [$empresa->id]);

        return response()->json(['categoria' => [
            'id' => $categoria->id,
            'nombre' => $categoria->nombre,
            'tipo_activo_id' => $categoria->tipo_activo_id,
        ]]);
    }

    /**
     * @param  array<int, int>  $empresaIds
     */
    private function crear(string $nombre, ?int $tipoActivoId, bool $activa, array $empresaIds): CategoriaActivo
    {
        $categoria = CategoriaActivo::query()->create([
            'tipo_activo_id' => $tipoActivoId,
            'nombre' => $nombre,
            'activa' => $activa,
        ]);

        $categoria->empresas()->sync(array_values(array_unique($empresaIds)));

        $this->auditar('categoria_crear', $categoria, 'Alta de categoría '.$categoria->nombre);

        return $categoria;
    }

    private function auditar(string $accion, CategoriaActivo $categoria, string $descripcion): void
    {
        foreach ($categoria->empresas()->pluck('empresas.id') as $empresaId) {
            $this->auditoria->registrar('activos', $accion, [
                'tipo_entidad' => CategoriaActivo::class, 'entidad_id' => $categoria->id, 'empresa_id' => (int) $empresaId,
                'descripcion' => $descripcion,
            ]);
        }
    }
}
