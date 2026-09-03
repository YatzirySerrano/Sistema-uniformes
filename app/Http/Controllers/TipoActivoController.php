<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Requests\Activos\GuardarTipoActivoRequest;
use App\Models\Empresa;
use App\Models\TipoActivo;
use App\Servicios\ServicioAuditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRUD del catálogo de tipos de activo (Prenda, Equipo de cómputo, Dispositivo
 * móvil…). Vive dentro del área de Activos (pantalla "Tipos y categorías"). No
 * hay borrado físico: los tipos en uso sólo se pueden desactivar. La empresa
 * llega en `empresa_id` y se valida el acceso del usuario.
 */
class TipoActivoController extends Controller
{
    use ConEmpresa;

    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    public function store(GuardarTipoActivoRequest $request): RedirectResponse
    {
        $this->crear($request->empresaResuelta(), $request->validated('nombre'), $request->boolean('activo', true));

        return back()->with('toast', ['type' => 'success', 'message' => 'Tipo de activo creado.']);
    }

    public function update(GuardarTipoActivoRequest $request, TipoActivo $tipo): RedirectResponse
    {
        $tipo->update([
            'nombre' => $request->validated('nombre'),
            'activo' => $request->boolean('activo', $tipo->activo),
        ]);

        $this->auditoria->registrar('activos', 'tipo_editar', [
            'tipo_entidad' => TipoActivo::class, 'entidad_id' => $tipo->id, 'empresa_id' => $tipo->empresa_id,
            'descripcion' => 'Edición de tipo de activo '.$tipo->nombre,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Tipo de activo actualizado.']);
    }

    public function toggle(TipoActivo $tipo): RedirectResponse
    {
        $this->authorize('administrar', $tipo);

        $tipo->update(['activo' => ! $tipo->activo]);

        $this->auditoria->registrar('activos', $tipo->activo ? 'tipo_activar' : 'tipo_desactivar', [
            'tipo_entidad' => TipoActivo::class, 'entidad_id' => $tipo->id, 'empresa_id' => $tipo->empresa_id,
            'descripcion' => ($tipo->activo ? 'Activación' : 'Desactivación').' de tipo de activo '.$tipo->nombre,
        ]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $tipo->activo ? 'Tipo de activo activado.' : 'Tipo de activo desactivado.',
        ]);
    }

    /**
     * Alta rápida desde el formulario de Activo. Devuelve el tipo ya creado.
     */
    public function rapido(Request $request): JsonResponse
    {
        $this->authorize('administrar', TipoActivo::class);
        $empresa = $this->resolverEmpresa($request);

        $datos = $request->validate([
            'nombre' => [
                'required', 'string', 'max:120',
                Rule::unique('tipos_activo', 'nombre')->where(fn ($q) => $q->where('empresa_id', $empresa->id)),
            ],
        ], [
            'nombre.required' => 'Escribe el nombre del nuevo tipo.',
            'nombre.unique' => 'Ya existe un tipo de activo con ese nombre en esta empresa.',
        ]);

        $tipo = $this->crear($empresa, trim($datos['nombre']), true);

        return response()->json(['tipo' => ['id' => $tipo->id, 'nombre' => $tipo->nombre]]);
    }

    private function crear(Empresa $empresa, string $nombre, bool $activo): TipoActivo
    {
        $tipo = TipoActivo::query()->create([
            'empresa_id' => $empresa->id,
            'nombre' => $nombre,
            'codigo' => $this->generarCodigo($empresa->id),
            'activo' => $activo,
        ]);

        $this->auditoria->registrar('activos', 'tipo_crear', [
            'tipo_entidad' => TipoActivo::class, 'entidad_id' => $tipo->id, 'empresa_id' => $empresa->id,
            'descripcion' => 'Alta de tipo de activo '.$tipo->nombre,
        ]);

        return $tipo;
    }

    private function generarCodigo(int $empresaId): string
    {
        $n = TipoActivo::query()->where('empresa_id', $empresaId)->count() + 1;

        do {
            $codigo = 'TAC-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            $n++;
        } while (TipoActivo::query()->where('empresa_id', $empresaId)->where('codigo', $codigo)->exists());

        return $codigo;
    }
}
