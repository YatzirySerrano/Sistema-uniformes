<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Requests\Activos\GuardarTipoActivoRequest;
use App\Models\Empresa;
use App\Models\TipoActivo;
use App\Servicios\ServicioAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * CRUD del catálogo COMPARTIDO de tipos de activo. El tipo no pertenece a una
 * empresa: `activo` es su estado global y `tipo_activo_empresa` lo habilita por
 * empresa. Vive en la pantalla "Tipos y categorías" del área de Activos. Sin
 * borrado físico.
 */
class TipoActivoController extends Controller
{
    use ConEmpresa;

    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    public function store(GuardarTipoActivoRequest $request): RedirectResponse
    {
        $empresaIds = $request->collect('empresa_ids')->map(fn ($id): int => (int) $id)->all();
        $tipo = $this->crear($request->validated('nombre'), $request->boolean('activo', true), $empresaIds);

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
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => $tipo->activo ? 'Tipo de activo activado.' : 'Tipo de activo desactivado.',
        ]);
    }

    /**
     * Sincroniza (bulk) las empresas para las que el tipo está habilitado —
     * diálogo "Empresas".
     */
    public function empresas(Request $request, TipoActivo $tipo): RedirectResponse
    {
        $this->authorize('administrar', $tipo);

        $idsAutorizadas = $this->idsEmpresasAutorizadas($request);

        $datos = $request->validate([
            'empresa_ids' => ['present', 'array'],
            'empresa_ids.*' => ['integer', Rule::in($idsAutorizadas->all())],
        ]);

        // Conserva las empresas fuera del alcance del usuario; sólo ajusta las suyas.
        $fuera = $tipo->empresas()->whereNotIn('empresas.id', $idsAutorizadas)->pluck('empresas.id');
        $tipo->empresas()->sync($fuera->merge($datos['empresa_ids'])->unique()->all());

        $this->auditar('tipo_empresas', $tipo, 'Actualización de empresas habilitadas para el tipo '.$tipo->nombre);

        $n = count($datos['empresa_ids']);

        return back()->with('toast', ['type' => 'success', 'message' => "Tipo «{$tipo->nombre}»: {$n} ".($n === 1 ? 'empresa habilitada.' : 'empresas habilitadas.')]);
    }

    /**
     * Habilita / deshabilita el tipo para UNA empresa (toggle desde el listado).
     * El mensaje nombra la empresa afectada.
     */
    public function empresa(Request $request, TipoActivo $tipo): RedirectResponse
    {
        $this->authorize('administrar', $tipo);

        $datos = $request->validate(['empresa_id' => ['required', 'integer']]);
        $empresa = Empresa::query()->findOrFail((int) $datos['empresa_id']);
        abort_unless($request->user()->puedeAccederEmpresa($empresa), 403);

        $estaba = $tipo->empresas()->whereKey($empresa->id)->exists();
        $estaba ? $tipo->empresas()->detach($empresa->id) : $tipo->empresas()->attach($empresa->id);

        $this->auditar(
            'tipo_empresas',
            $tipo,
            ($estaba ? 'Deshabilitación' : 'Habilitación')." del tipo {$tipo->nombre} para {$empresa->nombre_comercial}",
        );

        $mensaje = $estaba
            ? "Tipo «{$tipo->nombre}» deshabilitado para «{$empresa->nombre_comercial}». Sigue disponible globalmente y en las demás empresas donde esté habilitado."
            : "Tipo «{$tipo->nombre}» habilitado para «{$empresa->nombre_comercial}».";

        return back()->with('toast', ['type' => 'success', 'message' => $mensaje]);
    }

    /**
     * Búsqueda con autocompletado para el combobox de tipo (formulario de Activo
     * y filtro del listado). Sólo tipos con estado global activo; si llega
     * `empresa_id` se acota a los habilitados para esa empresa (inválida / sin
     * acceso → lista vacía), si no, a los habilitados para alguna empresa
     * autorizada del usuario.
     */
    public function buscar(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TipoActivo::class);

        $termino = trim((string) $request->query('q', ''));
        $consulta = TipoActivo::query()->where('activo', true);

        if ($request->filled('empresa_id')) {
            $empresa = $this->empresaDelFiltro($request);

            if ($empresa === null) {
                return response()->json(['tipos' => []]);
            }

            $consulta->paraEmpresa($empresa->id);
        } else {
            $consulta->whereHas('empresas', fn (Builder $q) => $q->whereIn('empresas.id', $this->idsEmpresasAutorizadas($request)));
        }

        $tipos = $consulta
            ->when($termino !== '', fn (Builder $q) => $q->where('nombre', 'like', "%{$termino}%"))
            ->orderBy('nombre')
            ->limit(20)
            ->get(['id', 'nombre'])
            ->map(fn (TipoActivo $t): array => ['id' => $t->id, 'nombre' => $t->nombre]);

        return response()->json(['tipos' => $tipos]);
    }

    /**
     * Alta rápida desde el combobox del formulario de Activo: crea el tipo y lo
     * habilita SÓLO para la empresa del formulario (el admin puede habilitarlo
     * para otras después).
     */
    public function rapido(Request $request): JsonResponse
    {
        $this->authorize('administrar', TipoActivo::class);
        $empresa = $this->resolverEmpresa($request);

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

        $tipo = $this->crear($nombre, true, [$empresa->id]);

        return response()->json(['tipo' => ['id' => $tipo->id, 'nombre' => $tipo->nombre]]);
    }

    /**
     * @param  array<int, int>  $empresaIds
     */
    private function crear(string $nombre, bool $activo, array $empresaIds): TipoActivo
    {
        $tipo = TipoActivo::query()->create([
            'nombre' => $nombre,
            'codigo' => $this->generarCodigo(),
            'activo' => $activo,
        ]);

        $tipo->empresas()->sync(array_values(array_unique($empresaIds)));

        $this->auditar('tipo_crear', $tipo, 'Alta de tipo de activo '.$tipo->nombre);

        return $tipo;
    }

    private function auditar(string $accion, TipoActivo $tipo, string $descripcion): void
    {
        foreach ($tipo->empresas()->pluck('empresas.id') as $empresaId) {
            $this->auditoria->registrar('activos', $accion, [
                'tipo_entidad' => TipoActivo::class, 'entidad_id' => $tipo->id, 'empresa_id' => (int) $empresaId,
                'descripcion' => $descripcion,
            ]);
        }
    }

    private function generarCodigo(): string
    {
        $n = TipoActivo::query()->count() + 1;

        do {
            $codigo = 'TAC-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            $n++;
        } while (TipoActivo::query()->where('codigo', $codigo)->exists());

        return $codigo;
    }
}
