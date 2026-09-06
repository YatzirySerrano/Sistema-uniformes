<?php

namespace App\Http\Controllers;

use App\Http\Requests\Activos\GuardarTipoActivoRequest;
use App\Models\TipoActivo;
use App\Servicios\ServicioAuditoria;
use App\Soporte\ServicioGeneradorCodigosGlobal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * CRUD del catálogo COMPARTIDO de tipos de activo. Es GLOBAL: visible para
 * todas las empresas por igual; `activo` es su único estado (retira el tipo de
 * nuevas selecciones en toda la plataforma). Vive en la pantalla "Tipos y
 * categorías" del área de Activos. Sin borrado físico.
 */
class TipoActivoController extends Controller
{
    public function __construct(
        private readonly ServicioAuditoria $auditoria,
        private readonly ServicioGeneradorCodigosGlobal $codigos,
    ) {}

    public function store(GuardarTipoActivoRequest $request): RedirectResponse
    {
        $this->crear($request->validated('nombre'), $request->boolean('activo', true));

        return back()->with('toast', ['type' => 'success', 'message' => 'Tipo de activo creado.']);
    }

    public function update(GuardarTipoActivoRequest $request, TipoActivo $tipo): RedirectResponse
    {
        $tipo->update([
            'nombre' => $request->validated('nombre'),
            'activo' => $request->boolean('activo', $tipo->activo),
        ]);

        $this->auditar('tipo_editar', $tipo, 'Edición de tipo de activo '.$tipo->nombre);

        return back()->with('toast', ['type' => 'success', 'message' => 'Tipo de activo actualizado.']);
    }

    public function toggle(TipoActivo $tipo): RedirectResponse
    {
        $this->authorize('administrar', $tipo);

        $tipo->update(['activo' => ! $tipo->activo]);

        $this->auditar(
            $tipo->activo ? 'tipo_activar' : 'tipo_desactivar',
            $tipo,
            ($tipo->activo ? 'Activación' : 'Desactivación').' global de tipo de activo '.$tipo->nombre,
            ['valores_anteriores' => ['activo' => ! $tipo->activo], 'valores_nuevos' => ['activo' => $tipo->activo]],
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => $tipo->activo ? 'Tipo de activo activado.' : 'Tipo de activo desactivado.',
        ]);
    }

    /**
     * Búsqueda con autocompletado para el combobox de tipo (formulario de
     * Activo y filtro del listado). Catálogo global: no depende de empresa.
     */
    public function buscar(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TipoActivo::class);

        $termino = trim((string) $request->query('q', ''));

        $tipos = TipoActivo::query()
            ->where('activo', true)
            ->when($termino !== '', fn (Builder $q) => $q->where('nombre', 'like', "%{$termino}%"))
            ->orderBy('nombre')
            ->limit(20)
            ->get(['id', 'nombre'])
            ->map(fn (TipoActivo $t): array => ['id' => $t->id, 'nombre' => $t->nombre]);

        return response()->json(['tipos' => $tipos]);
    }

    /**
     * Alta rápida desde el combobox del formulario de Activo: crea el tipo en
     * el catálogo global.
     */
    public function rapido(Request $request): JsonResponse
    {
        $this->authorize('administrar', TipoActivo::class);

        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
        ], [
            'nombre.required' => 'Escribe el nombre del nuevo tipo.',
        ]);

        $nombre = trim($datos['nombre']);

        if (TipoActivo::existeNombre($nombre)) {
            throw ValidationException::withMessages([
                'nombre' => 'Ya existe un tipo de activo con ese nombre.',
            ]);
        }

        $tipo = $this->crear($nombre, true);

        return response()->json(['tipo' => ['id' => $tipo->id, 'nombre' => $tipo->nombre]]);
    }

    private function crear(string $nombre, bool $activo): TipoActivo
    {
        $tipo = TipoActivo::query()->create([
            'nombre' => $nombre,
            'codigo' => $this->generarCodigo(),
            'activo' => $activo,
        ]);

        $this->auditar('tipo_crear', $tipo, 'Alta de tipo de activo '.$tipo->nombre);

        return $tipo;
    }

    /**
     * @param  array<string, mixed>  $valores
     */
    private function auditar(string $accion, TipoActivo $tipo, string $descripcion, array $valores = []): void
    {
        $this->auditoria->registrar('activos', $accion, [
            'tipo_entidad' => TipoActivo::class, 'entidad_id' => $tipo->id,
            'descripcion' => $descripcion,
            ...$valores,
        ]);
    }

    private function generarCodigo(): string
    {
        return $this->codigos->siguiente('tipo_activo', 'TAC');
    }
}
