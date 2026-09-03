<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConEmpresaActiva;
use App\Http\Requests\Activos\GuardarTipoActivoRequest;
use App\Models\Activo;
use App\Models\TipoActivo;
use App\Servicios\ServicioAuditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRUD del catálogo de tipos de activo (Prenda, Equipo de cómputo, Dispositivo
 * móvil…). Vive dentro del área de Activos (pantalla "Tipos y categorías") para
 * no saturar el menú lateral. No hay borrado físico: los tipos en uso sólo se
 * pueden desactivar.
 */
class TipoActivoController extends Controller
{
    use ConEmpresaActiva;

    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    public function store(GuardarTipoActivoRequest $request): RedirectResponse
    {
        $this->crear($request->validated('nombre'), $request->boolean('activo', true));

        return back()->with('toast', ['type' => 'success', 'message' => 'Tipo de activo creado.']);
    }

    public function update(GuardarTipoActivoRequest $request, TipoActivo $tipo): RedirectResponse
    {
        $this->verificarEmpresa($tipo);

        $tipo->update([
            'nombre' => $request->validated('nombre'),
            'activo' => $request->boolean('activo', $tipo->activo),
        ]);

        $this->auditoria->registrar('activos', 'tipo_editar', [
            'tipo_entidad' => TipoActivo::class, 'entidad_id' => $tipo->id,
            'descripcion' => 'Edición de tipo de activo '.$tipo->nombre,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Tipo de activo actualizado.']);
    }

    public function toggle(TipoActivo $tipo): RedirectResponse
    {
        $this->authorize('administrar', $tipo);
        $this->verificarEmpresa($tipo);

        $tipo->update(['activo' => ! $tipo->activo]);

        $this->auditoria->registrar('activos', $tipo->activo ? 'tipo_activar' : 'tipo_desactivar', [
            'tipo_entidad' => TipoActivo::class, 'entidad_id' => $tipo->id,
            'descripcion' => ($tipo->activo ? 'Activación' : 'Desactivación').' de tipo de activo '.$tipo->nombre,
        ]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $tipo->activo ? 'Tipo de activo activado.' : 'Tipo de activo desactivado.',
        ]);
    }

    /**
     * Alta rápida desde el formulario de Activo (opción "Otro / crear nuevo
     * tipo"). Devuelve el tipo ya creado para seleccionarlo en el acto.
     */
    public function rapido(Request $request): JsonResponse
    {
        $this->authorize('administrar', TipoActivo::class);
        $empresaId = $this->empresaActiva()->id;

        $datos = $request->validate([
            'nombre' => [
                'required', 'string', 'max:120',
                Rule::unique('tipos_activo', 'nombre')->where(fn ($q) => $q->where('empresa_id', $empresaId)),
            ],
        ], [
            'nombre.required' => 'Escribe el nombre del nuevo tipo.',
            'nombre.unique' => 'Ya existe un tipo de activo con ese nombre en esta empresa.',
        ]);

        $tipo = $this->crear(trim($datos['nombre']), true);

        return response()->json(['tipo' => ['id' => $tipo->id, 'nombre' => $tipo->nombre]]);
    }

    private function crear(string $nombre, bool $activo): TipoActivo
    {
        $empresaId = $this->empresaActiva()->id;

        $tipo = TipoActivo::query()->create([
            'empresa_id' => $empresaId,
            'nombre' => $nombre,
            'codigo' => $this->generarCodigo($empresaId),
            'activo' => $activo,
        ]);

        $this->auditoria->registrar('activos', 'tipo_crear', [
            'tipo_entidad' => TipoActivo::class, 'entidad_id' => $tipo->id,
            'descripcion' => 'Alta de tipo de activo '.$tipo->nombre,
        ]);

        return $tipo;
    }

    private function verificarEmpresa(TipoActivo $tipo): void
    {
        abort_unless($tipo->empresa_id === $this->empresaActiva()->id, 404);
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
