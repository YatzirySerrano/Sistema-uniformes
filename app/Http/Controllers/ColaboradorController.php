<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConEmpresaActiva;
use App\Http\Requests\Colaboradores\GuardarColaboradorRequest;
use App\Models\Colaborador;
use App\Servicios\ServicioAuditoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ColaboradorController extends Controller
{
    use ConEmpresaActiva;

    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Colaborador::class);
        $empresa = $this->empresaActiva();

        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'sucursal_id' => ['nullable', 'integer'],
            'estado' => ['nullable', 'in:activos,inactivos,todos'],
        ]);

        $colaboradores = Colaborador::query()
            ->where('empresa_id', $empresa->id)
            ->when($filtros['buscar'] ?? null, fn ($q, $b) => $q->where(fn ($s) => $s
                ->where('nombre_completo', 'like', "%{$b}%")
                ->orWhere('numero_empleado', 'like', "%{$b}%")))
            ->when($filtros['sucursal_id'] ?? null, fn ($q, $s) => $q->where('sucursal_id', $s))
            ->when(($filtros['estado'] ?? 'activos') === 'activos', fn ($q) => $q->where('activo', true))
            ->when(($filtros['estado'] ?? null) === 'inactivos', fn ($q) => $q->where('activo', false))
            ->with('sucursal:id,nombre')
            ->orderBy('nombre_completo')
            ->paginate($this->porPagina())
            ->withQueryString();

        return Inertia::render('Colaboradores/Index', [
            'colaboradores' => $colaboradores,
            'filtros' => $filtros,
            'sucursales' => $this->contexto()->sucursalesDisponibles()->map->only(['id', 'nombre'])->values(),
            'puedeCrear' => $request->user()->can('create', Colaborador::class),
            'puedeImportar' => $request->user()->can('importar', Colaborador::class),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Colaborador::class);

        $sucursalId = $request->integer('sucursal_id') ?: null;

        return Inertia::render('Colaboradores/Formulario', [
            'colaborador' => null,
            'sucursales' => $this->contexto()->sucursalesDisponibles()->map->only(['id', 'nombre'])->values(),
            // Preselección desde ?sucursal_id (p. ej. al llegar desde una
            // sucursal específica); sólo se respeta si el usuario tiene
            // acceso a esa sucursal dentro de la empresa activa.
            'sucursalPreseleccionadaId' => $sucursalId && $this->contexto()->puedeVerSucursal($sucursalId)
                ? $sucursalId
                : null,
        ]);
    }

    public function store(GuardarColaboradorRequest $request): RedirectResponse
    {
        $empresa = $this->empresaActiva();

        $colaborador = Colaborador::query()->create([
            ...$request->safe()->except('activo'),
            'empresa_id' => $empresa->id,
            'activo' => $request->boolean('activo', true),
        ]);

        $this->auditoria->registrar('colaboradores', 'crear', [
            'tipo_entidad' => Colaborador::class,
            'entidad_id' => $colaborador->id,
            'descripcion' => 'Alta de colaborador '.$colaborador->nombre_completo,
            'valores_nuevos' => $colaborador->toArray(),
        ]);

        return to_route('colaboradores.index')->with('toast', ['type' => 'success', 'message' => 'Colaborador registrado.']);
    }

    public function edit(Colaborador $colaborador): Response
    {
        $this->authorize('update', $colaborador);
        $this->verificarEmpresa($colaborador);

        return Inertia::render('Colaboradores/Formulario', [
            'colaborador' => $colaborador->only(['id', 'numero_empleado', 'nombre_completo', 'sucursal_id', 'puesto', 'area', 'correo', 'activo']),
            'sucursales' => $this->contexto()->sucursalesDisponibles()->map->only(['id', 'nombre'])->values(),
        ]);
    }

    public function update(GuardarColaboradorRequest $request, Colaborador $colaborador): RedirectResponse
    {
        $this->verificarEmpresa($colaborador);
        $anteriores = $colaborador->toArray();

        $colaborador->update([
            ...$request->safe()->except('activo'),
            'activo' => $request->boolean('activo', $colaborador->activo),
        ]);

        $this->auditoria->registrar('colaboradores', 'editar', [
            'tipo_entidad' => Colaborador::class,
            'entidad_id' => $colaborador->id,
            'descripcion' => 'Edición de colaborador '.$colaborador->nombre_completo,
            'valores_anteriores' => $anteriores,
            'valores_nuevos' => $colaborador->toArray(),
        ]);

        return to_route('colaboradores.index')->with('toast', ['type' => 'success', 'message' => 'Colaborador actualizado.']);
    }

    public function toggle(Colaborador $colaborador): RedirectResponse
    {
        $this->authorize('desactivar', $colaborador);
        $this->verificarEmpresa($colaborador);

        $colaborador->update(['activo' => ! $colaborador->activo]);

        $this->auditoria->registrar('colaboradores', $colaborador->activo ? 'activar' : 'desactivar', [
            'tipo_entidad' => Colaborador::class,
            'entidad_id' => $colaborador->id,
            'descripcion' => ($colaborador->activo ? 'Activación' : 'Desactivación').' de '.$colaborador->nombre_completo,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => $colaborador->activo ? 'Colaborador activado.' : 'Colaborador desactivado.']);
    }

    private function verificarEmpresa(Colaborador $colaborador): void
    {
        abort_unless($colaborador->empresa_id === $this->empresaActiva()->id, 404);
    }
}
