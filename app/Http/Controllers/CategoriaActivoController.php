<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Requests\Activos\GuardarCategoriaActivoRequest;
use App\Models\CategoriaActivo;
use App\Models\Empresa;
use App\Servicios\ServicioAuditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRUD del catálogo de categorías de activo (Camisola, Pantalón, Laptop…).
 * Puede relacionarse con un tipo de activo. Sin borrado físico. La empresa llega
 * en `empresa_id` y se valida el acceso del usuario.
 */
class CategoriaActivoController extends Controller
{
    use ConEmpresa;

    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    public function store(GuardarCategoriaActivoRequest $request): RedirectResponse
    {
        $this->crear(
            $request->empresaResuelta(),
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

        $this->auditoria->registrar('activos', 'categoria_editar', [
            'tipo_entidad' => CategoriaActivo::class, 'entidad_id' => $categoria->id, 'empresa_id' => $categoria->empresa_id,
            'descripcion' => 'Edición de categoría '.$categoria->nombre,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Categoría actualizada.']);
    }

    public function toggle(CategoriaActivo $categoria): RedirectResponse
    {
        $this->authorize('administrar', $categoria);

        $categoria->update(['activa' => ! $categoria->activa]);

        $this->auditoria->registrar('activos', $categoria->activa ? 'categoria_activar' : 'categoria_desactivar', [
            'tipo_entidad' => CategoriaActivo::class, 'entidad_id' => $categoria->id, 'empresa_id' => $categoria->empresa_id,
            'descripcion' => ($categoria->activa ? 'Activación' : 'Desactivación').' de categoría '.$categoria->nombre,
        ]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $categoria->activa ? 'Categoría activada.' : 'Categoría desactivada.',
        ]);
    }

    /**
     * Alta rápida desde el formulario de Activo. Devuelve la categoría creada.
     */
    public function rapido(Request $request): JsonResponse
    {
        $this->authorize('administrar', CategoriaActivo::class);
        $empresa = $this->resolverEmpresa($request);

        $datos = $request->validate([
            'nombre' => [
                'required', 'string', 'max:120',
                Rule::unique('categorias_activo', 'nombre')->where(fn ($q) => $q->where('empresa_id', $empresa->id)),
            ],
            'tipo_activo_id' => [
                'nullable', 'integer',
                Rule::exists('tipos_activo', 'id')->where(fn ($q) => $q->where('empresa_id', $empresa->id)),
            ],
        ], [
            'nombre.required' => 'Escribe el nombre de la nueva categoría.',
            'nombre.unique' => 'Ya existe una categoría con ese nombre en esta empresa.',
        ]);

        $categoria = $this->crear($empresa, trim($datos['nombre']), $datos['tipo_activo_id'] ?? null, true);

        return response()->json(['categoria' => ['id' => $categoria->id, 'nombre' => $categoria->nombre]]);
    }

    private function crear(Empresa $empresa, string $nombre, ?int $tipoActivoId, bool $activa): CategoriaActivo
    {
        $categoria = CategoriaActivo::query()->create([
            'empresa_id' => $empresa->id,
            'tipo_activo_id' => $tipoActivoId,
            'nombre' => $nombre,
            'activa' => $activa,
        ]);

        $this->auditoria->registrar('activos', 'categoria_crear', [
            'tipo_entidad' => CategoriaActivo::class, 'entidad_id' => $categoria->id, 'empresa_id' => $empresa->id,
            'descripcion' => 'Alta de categoría '.$categoria->nombre,
        ]);

        return $categoria;
    }
}
