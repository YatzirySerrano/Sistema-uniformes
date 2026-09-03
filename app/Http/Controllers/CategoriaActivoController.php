<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConEmpresaActiva;
use App\Http\Requests\Activos\GuardarCategoriaActivoRequest;
use App\Models\CategoriaActivo;
use App\Servicios\ServicioAuditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRUD del catálogo de categorías de activo (Camisola, Pantalón, Laptop,
 * Teléfono celular…). Sustituye al texto libre. Puede relacionarse con un tipo
 * de activo. Sin borrado físico: las categorías en uso sólo se desactivan.
 */
class CategoriaActivoController extends Controller
{
    use ConEmpresaActiva;

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
        $this->verificarEmpresa($categoria);

        $categoria->update([
            'nombre' => $request->validated('nombre'),
            'tipo_activo_id' => $request->integer('tipo_activo_id') ?: null,
            'activa' => $request->boolean('activa', $categoria->activa),
        ]);

        $this->auditoria->registrar('activos', 'categoria_editar', [
            'tipo_entidad' => CategoriaActivo::class, 'entidad_id' => $categoria->id,
            'descripcion' => 'Edición de categoría '.$categoria->nombre,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Categoría actualizada.']);
    }

    public function toggle(CategoriaActivo $categoria): RedirectResponse
    {
        $this->authorize('administrar', $categoria);
        $this->verificarEmpresa($categoria);

        $categoria->update(['activa' => ! $categoria->activa]);

        $this->auditoria->registrar('activos', $categoria->activa ? 'categoria_activar' : 'categoria_desactivar', [
            'tipo_entidad' => CategoriaActivo::class, 'entidad_id' => $categoria->id,
            'descripcion' => ($categoria->activa ? 'Activación' : 'Desactivación').' de categoría '.$categoria->nombre,
        ]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $categoria->activa ? 'Categoría activada.' : 'Categoría desactivada.',
        ]);
    }

    /**
     * Alta rápida desde el formulario de Activo (opción "Otra / crear
     * categoría"). Devuelve la categoría creada para seleccionarla en el acto.
     */
    public function rapido(Request $request): JsonResponse
    {
        $this->authorize('administrar', CategoriaActivo::class);
        $empresaId = $this->empresaActiva()->id;

        $datos = $request->validate([
            'nombre' => [
                'required', 'string', 'max:120',
                Rule::unique('categorias_activo', 'nombre')->where(fn ($q) => $q->where('empresa_id', $empresaId)),
            ],
            'tipo_activo_id' => [
                'nullable', 'integer',
                Rule::exists('tipos_activo', 'id')->where(fn ($q) => $q->where('empresa_id', $empresaId)),
            ],
        ], [
            'nombre.required' => 'Escribe el nombre de la nueva categoría.',
            'nombre.unique' => 'Ya existe una categoría con ese nombre en esta empresa.',
        ]);

        $categoria = $this->crear(trim($datos['nombre']), $datos['tipo_activo_id'] ?? null, true);

        return response()->json(['categoria' => ['id' => $categoria->id, 'nombre' => $categoria->nombre]]);
    }

    private function crear(string $nombre, ?int $tipoActivoId, bool $activa): CategoriaActivo
    {
        $categoria = CategoriaActivo::query()->create([
            'empresa_id' => $this->empresaActiva()->id,
            'tipo_activo_id' => $tipoActivoId,
            'nombre' => $nombre,
            'activa' => $activa,
        ]);

        $this->auditoria->registrar('activos', 'categoria_crear', [
            'tipo_entidad' => CategoriaActivo::class, 'entidad_id' => $categoria->id,
            'descripcion' => 'Alta de categoría '.$categoria->nombre,
        ]);

        return $categoria;
    }

    private function verificarEmpresa(CategoriaActivo $categoria): void
    {
        abort_unless($categoria->empresa_id === $this->empresaActiva()->id, 404);
    }
}
