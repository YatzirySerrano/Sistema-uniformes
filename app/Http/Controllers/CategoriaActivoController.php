<?php

namespace App\Http\Controllers;

use App\Http\Requests\Activos\GuardarCategoriaActivoRequest;
use App\Models\CategoriaActivo;
use App\Servicios\ServicioAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * CRUD del catálogo COMPARTIDO de categorías de activo. Es GLOBAL: visible
 * para todas las empresas por igual; `activa` es su único estado. Puede
 * relacionarse (opcionalmente) con un tipo del catálogo compartido. Sin
 * borrado físico.
 */
class CategoriaActivoController extends Controller
{
    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    public function store(GuardarCategoriaActivoRequest $request): RedirectResponse
    {
        $this->crear(
            $request->validated('nombre'),
            $request->integer('tipo_activo_id') ?: null,
            $request->boolean('activa', true),
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
     * Búsqueda con autocompletado para el combobox de categoría (formulario de
     * Activo y filtro del listado). Catálogo global: no depende de empresa.
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
     * Alta rápida desde el combobox del formulario de Activo: crea la
     * categoría en el catálogo global.
     */
    public function rapido(Request $request): JsonResponse
    {
        $this->authorize('administrar', CategoriaActivo::class);

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

        $categoria = $this->crear($nombre, $datos['tipo_activo_id'] ?? null, true);

        return response()->json(['categoria' => [
            'id' => $categoria->id,
            'nombre' => $categoria->nombre,
            'tipo_activo_id' => $categoria->tipo_activo_id,
        ]]);
    }

    private function crear(string $nombre, ?int $tipoActivoId, bool $activa): CategoriaActivo
    {
        $categoria = CategoriaActivo::query()->create([
            'tipo_activo_id' => $tipoActivoId,
            'nombre' => $nombre,
            'activa' => $activa,
        ]);

        $this->auditar('categoria_crear', $categoria, 'Alta de categoría '.$categoria->nombre);

        return $categoria;
    }

    private function auditar(string $accion, CategoriaActivo $categoria, string $descripcion): void
    {
        $this->auditoria->registrar('activos', $accion, [
            'tipo_entidad' => CategoriaActivo::class, 'entidad_id' => $categoria->id,
            'descripcion' => $descripcion,
        ]);
    }
}
