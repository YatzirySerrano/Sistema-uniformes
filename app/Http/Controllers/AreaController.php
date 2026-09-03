<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Requests\Areas\GuardarAreaRequest;
use App\Models\Area;
use App\Servicios\ServicioAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AreaController extends Controller
{
    use ConEmpresa;

    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Area::class);

        $usuario = $request->user();
        $idsAutorizadas = $this->idsEmpresasAutorizadas($request);
        $empresaFiltro = $this->empresaDelFiltro($request);

        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', 'in:activas,inactivas'],
            'orden' => ['nullable', 'in:az,za'],
        ]);

        $orden = ($filtros['orden'] ?? 'az') === 'za' ? 'desc' : 'asc';

        $areas = Area::query()
            ->whereIn('empresa_id', $idsAutorizadas)
            ->when($empresaFiltro !== null, fn (Builder $q) => $q->where('empresa_id', $empresaFiltro->id))
            ->with('empresa:id,nombre_comercial')
            ->withCount(['colaboradores', 'colaboradoresActivos'])
            ->when($filtros['buscar'] ?? null, function (Builder $q, string $buscar): void {
                $q->where(function (Builder $sub) use ($buscar): void {
                    $sub->where('nombre', 'like', "%{$buscar}%")
                        ->orWhere('codigo', 'like', "%{$buscar}%");
                });
            })
            ->when(($filtros['estado'] ?? null) === 'activas', fn (Builder $q) => $q->where('activa', true))
            ->when(($filtros['estado'] ?? null) === 'inactivas', fn (Builder $q) => $q->where('activa', false))
            ->orderBy('nombre', $orden)
            ->paginate($this->porPagina())
            ->withQueryString()
            ->through(fn (Area $a): array => [
                'id' => $a->id,
                'nombre' => $a->nombre,
                'codigo' => $a->codigo,
                'descripcion' => $a->descripcion,
                'activa' => $a->activa,
                'empresa' => ['id' => $a->empresa_id, 'nombre_comercial' => $a->empresa?->nombre_comercial],
                'colaboradores_total' => (int) $a->colaboradores_count,
                'colaboradores_activos' => (int) $a->colaboradores_activos_count,
            ]);

        return Inertia::render('Areas/Index', [
            'areas' => $areas,
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
            'filtros' => [
                'buscar' => $filtros['buscar'] ?? '',
                'estado' => $filtros['estado'] ?? '',
                'orden' => $filtros['orden'] ?? 'az',
                'empresa_id' => $empresaFiltro?->id,
            ],
            'permisos' => [
                'crear' => $usuario->can('create', Area::class),
                'editar' => $usuario->can('areas.editar'),
                'desactivar' => $usuario->can('areas.desactivar'),
            ],
        ]);
    }

    public function show(Request $request, Area $area): Response
    {
        $this->authorize('view', $area);

        $area->loadCount(['colaboradores', 'colaboradoresActivos']);
        $area->load('empresa:id,nombre_comercial');

        return Inertia::render('Areas/Detalle', [
            'area' => [
                ...$area->only(['id', 'nombre', 'codigo', 'descripcion', 'activa']),
                'empresa' => [
                    'id' => $area->empresa->id,
                    'nombre_comercial' => $area->empresa->nombre_comercial,
                ],
                'colaboradores_total' => (int) $area->colaboradores_count,
                'colaboradores_activos' => (int) $area->colaboradores_activos_count,
            ],
            'permisos' => [
                'editar' => $request->user()->can('update', $area),
                'desactivar' => $request->user()->can('desactivar', $area),
            ],
        ]);
    }

    public function store(GuardarAreaRequest $request): RedirectResponse
    {
        $empresa = $request->empresaResuelta();

        $datos = $request->validated();
        $datos['codigo'] = ($datos['codigo'] ?? null) ?: $this->generarCodigo($empresa->id);

        $area = Area::query()->create([
            ...$datos,
            'empresa_id' => $empresa->id,
            'activa' => true,
        ]);

        $this->auditoria->registrar('areas', 'crear', [
            'tipo_entidad' => Area::class, 'entidad_id' => $area->id, 'empresa_id' => $empresa->id,
            'descripcion' => 'Alta de área '.$area->nombre,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Área registrada correctamente.']);
    }

    public function update(GuardarAreaRequest $request, Area $area): RedirectResponse
    {
        $area->update($request->validated());

        $this->auditoria->registrar('areas', 'editar', [
            'tipo_entidad' => Area::class, 'entidad_id' => $area->id, 'empresa_id' => $area->empresa_id,
            'descripcion' => 'Edición de área '.$area->nombre,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Área actualizada correctamente.']);
    }

    public function toggle(Area $area): RedirectResponse
    {
        $this->authorize('desactivar', $area);

        $area->update(['activa' => ! $area->activa]);

        $this->auditoria->registrar('areas', $area->activa ? 'activar' : 'desactivar', [
            'tipo_entidad' => Area::class, 'entidad_id' => $area->id, 'empresa_id' => $area->empresa_id,
            'descripcion' => ($area->activa ? 'Activación' : 'Desactivación').' de área '.$area->nombre,
        ]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $area->activa ? 'Área activada correctamente.' : 'Área desactivada correctamente.',
        ]);
    }

    /**
     * Genera un código consecutivo y único dentro de la empresa
     * (ARE-0001, ARE-0002, …) cuando el usuario no captura uno.
     */
    private function generarCodigo(int $empresaId): string
    {
        $n = Area::query()->withTrashed()->where('empresa_id', $empresaId)->count() + 1;

        do {
            $codigo = 'ARE-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            $n++;
        } while (Area::query()->withTrashed()->where('empresa_id', $empresaId)->where('codigo', $codigo)->exists());

        return $codigo;
    }
}
