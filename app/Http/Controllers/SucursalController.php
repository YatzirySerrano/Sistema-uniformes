<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Requests\Sucursales\GuardarSucursalRequest;
use App\Models\Sucursal;
use App\Servicios\ServicioAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SucursalController extends Controller
{
    use ConEmpresa;

    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Sucursal::class);

        $usuario = $request->user();
        $idsAutorizadas = $this->idsEmpresasAutorizadas($request);
        $empresaFiltro = $this->empresaDelFiltro($request);

        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', 'in:activas,inactivas'],
            'orden' => ['nullable', 'in:az,za'],
        ]);

        $orden = ($filtros['orden'] ?? 'az') === 'za' ? 'desc' : 'asc';

        // Alcance de sucursales visibles: por empresa autorizada, sólo las del
        // alcance del usuario (todas si es global o no tiene asignación específica).
        $sucursalesVisibles = $idsAutorizadas
            ->flatMap(fn (int $empresaId): array => $this->acceso()->sucursalesAutorizadas($usuario, $empresaId)->pluck('id')->all())
            ->unique()
            ->values();

        $sucursales = Sucursal::query()
            ->whereIn('id', $sucursalesVisibles)
            ->when($empresaFiltro !== null, fn (Builder $q) => $q->where('empresa_id', $empresaFiltro->id))
            ->with('empresa:id,nombre_comercial')
            ->withCount('colaboradoresActivos')
            ->when($filtros['buscar'] ?? null, function (Builder $q, string $buscar): void {
                $q->where(function (Builder $sub) use ($buscar): void {
                    $sub->where('nombre', 'like', "%{$buscar}%")
                        ->orWhere('codigo', 'like', "%{$buscar}%")
                        ->orWhere('direccion', 'like', "%{$buscar}%");
                });
            })
            ->when(($filtros['estado'] ?? null) === 'activas', fn (Builder $q) => $q->where('activa', true))
            ->when(($filtros['estado'] ?? null) === 'inactivas', fn (Builder $q) => $q->where('activa', false))
            ->orderBy('nombre', $orden)
            ->paginate($this->porPagina())
            ->withQueryString()
            ->through(fn (Sucursal $s): array => [
                'id' => $s->id,
                'codigo' => $s->codigo,
                'nombre' => $s->nombre,
                'direccion' => $s->direccion,
                'telefono' => $s->telefono,
                'activa' => $s->activa,
                'empresa' => ['id' => $s->empresa_id, 'nombre_comercial' => $s->empresa?->nombre_comercial],
                'colaboradores_activos' => (int) $s->colaboradores_activos_count,
            ]);

        return Inertia::render('Sucursales/Index', [
            'sucursales' => $sucursales,
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
            'filtros' => [
                'buscar' => $filtros['buscar'] ?? '',
                'estado' => $filtros['estado'] ?? '',
                'orden' => $filtros['orden'] ?? 'az',
                'empresa_id' => $empresaFiltro?->id,
            ],
            'permisos' => [
                'crear' => $usuario->can('create', Sucursal::class),
                'editar' => $usuario->can('sucursales.editar'),
                'desactivar' => $usuario->can('sucursales.desactivar'),
            ],
        ]);
    }

    public function show(Request $request, Sucursal $sucursal): Response
    {
        $this->authorize('view', $sucursal);

        $sucursal->loadCount(['colaboradores', 'colaboradoresActivos']);
        $sucursal->load('empresa:id,nombre_comercial');

        return Inertia::render('Sucursales/Detalle', [
            'sucursal' => [
                ...$sucursal->only(['id', 'codigo', 'nombre', 'direccion', 'telefono', 'activa']),
                'empresa' => [
                    'id' => $sucursal->empresa->id,
                    'nombre_comercial' => $sucursal->empresa->nombre_comercial,
                ],
                'colaboradores_total' => (int) $sucursal->colaboradores_count,
                'colaboradores_activos' => (int) $sucursal->colaboradores_activos_count,
            ],
            'permisos' => [
                'editar' => $request->user()->can('update', $sucursal),
                'desactivar' => $request->user()->can('desactivar', $sucursal),
            ],
        ]);
    }

    public function store(GuardarSucursalRequest $request): RedirectResponse
    {
        $empresa = $request->empresaResuelta();

        $datos = $request->validated();
        $datos['codigo'] = ($datos['codigo'] ?? null) ?: $this->generarCodigo($empresa->id);

        $sucursal = Sucursal::query()->create([
            ...$datos,
            'empresa_id' => $empresa->id,
            'activa' => true,
        ]);

        $this->auditoria->registrar('sucursales', 'crear', [
            'tipo_entidad' => Sucursal::class, 'entidad_id' => $sucursal->id,
            'empresa_id' => $empresa->id, 'sucursal_id' => $sucursal->id,
            'descripcion' => 'Alta de sucursal '.$sucursal->nombre,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Sucursal registrada correctamente.']);
    }

    public function update(GuardarSucursalRequest $request, Sucursal $sucursal): RedirectResponse
    {
        $sucursal->update($request->validated());

        $this->auditoria->registrar('sucursales', 'editar', [
            'tipo_entidad' => Sucursal::class, 'entidad_id' => $sucursal->id,
            'empresa_id' => $sucursal->empresa_id, 'sucursal_id' => $sucursal->id,
            'descripcion' => 'Edición de sucursal '.$sucursal->nombre,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Sucursal actualizada correctamente.']);
    }

    public function toggle(Sucursal $sucursal): RedirectResponse
    {
        $this->authorize('desactivar', $sucursal);

        $sucursal->update(['activa' => ! $sucursal->activa]);

        $this->auditoria->registrar('sucursales', $sucursal->activa ? 'activar' : 'desactivar', [
            'tipo_entidad' => Sucursal::class, 'entidad_id' => $sucursal->id,
            'empresa_id' => $sucursal->empresa_id, 'sucursal_id' => $sucursal->id,
            'descripcion' => ($sucursal->activa ? 'Activación' : 'Desactivación').' de sucursal '.$sucursal->nombre,
        ]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $sucursal->activa ? 'Sucursal activada correctamente.' : 'Sucursal desactivada correctamente.',
        ]);
    }

    /**
     * Genera un código interno consecutivo y único dentro de la empresa
     * (SUC-0001, SUC-0002, …) cuando el usuario no captura uno.
     */
    private function generarCodigo(int $empresaId): string
    {
        $n = Sucursal::query()->withTrashed()->where('empresa_id', $empresaId)->count() + 1;

        do {
            $codigo = 'SUC-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            $n++;
        } while (Sucursal::query()->withTrashed()->where('empresa_id', $empresaId)->where('codigo', $codigo)->exists());

        return $codigo;
    }
}
