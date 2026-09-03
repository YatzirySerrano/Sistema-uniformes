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
     * Búsqueda con autocompletado para el combobox de categoría (formulario de
     * Activo y filtro del listado). Sólo categorías activas y autorizadas; si se
     * envía `empresa_id` se acota a esa empresa (inválida / sin acceso → lista
     * vacía), si no, a todas las empresas autorizadas.
     *
     * Si llega `tipo_activo_id`, se priorizan (y acotan) las categorías de ese
     * tipo más las que no tienen tipo: una categoría ligada a OTRO tipo no se
     * ofrece en ese contexto, pero las categorías sin tipo siempre se pueden
     * elegir (tipo y categoría son opcionales e independientes).
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

            $consulta->where('empresa_id', $empresa->id);
        } else {
            $consulta->whereIn('empresa_id', $this->idsEmpresasAutorizadas($request));
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
     * Alta rápida desde el combobox del formulario de Activo. Devuelve la
     * categoría creada para seleccionarla en el acto.
     */
    public function rapido(Request $request): JsonResponse
    {
        $this->authorize('administrar', CategoriaActivo::class);
        $empresa = $this->resolverEmpresa($request);

        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'tipo_activo_id' => [
                'nullable', 'integer',
                Rule::exists('tipos_activo', 'id')->where(fn ($q) => $q->where('empresa_id', $empresa->id)),
            ],
        ], [
            'nombre.required' => 'Escribe el nombre de la nueva categoría.',
            'tipo_activo_id.exists' => 'El tipo de activo seleccionado no pertenece a esta empresa.',
        ]);

        $nombre = trim($datos['nombre']);

        if (CategoriaActivo::existeNombreEnEmpresa($empresa->id, $nombre)) {
            throw ValidationException::withMessages([
                'nombre' => 'Ya existe una categoría con ese nombre en esta empresa.',
            ]);
        }

        $categoria = $this->crear($empresa, $nombre, $datos['tipo_activo_id'] ?? null, true);

        return response()->json(['categoria' => [
            'id' => $categoria->id,
            'nombre' => $categoria->nombre,
            'tipo_activo_id' => $categoria->tipo_activo_id,
        ]]);
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
