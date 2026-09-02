<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConEmpresaActiva;
use App\Http\Requests\Sucursales\GuardarSucursalRequest;
use App\Models\Sucursal;
use App\Servicios\ServicioAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SucursalController extends Controller
{
    use ConEmpresaActiva;

    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Sucursal::class);

        $empresa = $this->empresaActiva();
        $usuario = $request->user();

        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', Rule::in(['activas', 'inactivas'])],
            'orden' => ['nullable', Rule::in(['az', 'za'])],
        ]);

        $orden = ($filtros['orden'] ?? 'az') === 'za' ? 'desc' : 'asc';

        $consulta = Sucursal::query()->where('empresa_id', $empresa->id);

        // Los roles restringidos sólo ven las sucursales de su alcance dentro
        // de la empresa activa; Superadministrador y Administrador ven todas.
        if (! $usuario->tieneAlcanceGlobal()) {
            $consulta->whereIn('id', $this->contexto()->sucursalesDisponibles()->pluck('id'));
        }

        $sucursales = $consulta
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
                'colaboradores_activos' => (int) $s->colaboradores_activos_count,
            ]);

        return Inertia::render('Sucursales/Index', [
            'sucursales' => $sucursales,
            'empresa' => ['id' => $empresa->id, 'nombre_comercial' => $empresa->nombre_comercial],
            'filtros' => [
                'buscar' => $filtros['buscar'] ?? '',
                'estado' => $filtros['estado'] ?? '',
                'orden' => $filtros['orden'] ?? 'az',
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
        abort_unless($sucursal->empresa_id === $this->empresaActiva()->id, 404);

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
        $empresa = $this->empresaActiva();

        $datos = $request->validated();
        $datos['codigo'] = ($datos['codigo'] ?? null) ?: $this->generarCodigo($empresa->id);

        $sucursal = Sucursal::query()->create([
            ...$datos,
            'empresa_id' => $empresa->id,
            'activa' => true,
        ]);

        $this->auditoria->registrar('sucursales', 'crear', [
            'tipo_entidad' => Sucursal::class, 'entidad_id' => $sucursal->id, 'sucursal_id' => $sucursal->id,
            'descripcion' => 'Alta de sucursal '.$sucursal->nombre,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Sucursal registrada correctamente.']);
    }

    public function update(GuardarSucursalRequest $request, Sucursal $sucursal): RedirectResponse
    {
        abort_unless($sucursal->empresa_id === $this->empresaActiva()->id, 404);

        $sucursal->update($request->validated());

        $this->auditoria->registrar('sucursales', 'editar', [
            'tipo_entidad' => Sucursal::class, 'entidad_id' => $sucursal->id, 'sucursal_id' => $sucursal->id,
            'descripcion' => 'Edición de sucursal '.$sucursal->nombre,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Sucursal actualizada correctamente.']);
    }

    public function toggle(Sucursal $sucursal): RedirectResponse
    {
        $this->authorize('desactivar', $sucursal);
        abort_unless($sucursal->empresa_id === $this->empresaActiva()->id, 404);

        $sucursal->update(['activa' => ! $sucursal->activa]);

        $this->auditoria->registrar('sucursales', $sucursal->activa ? 'activar' : 'desactivar', [
            'tipo_entidad' => Sucursal::class, 'entidad_id' => $sucursal->id, 'sucursal_id' => $sucursal->id,
            'descripcion' => ($sucursal->activa ? 'Activación' : 'Desactivación').' de sucursal '.$sucursal->nombre,
        ]);

        $mensaje = $sucursal->activa
            ? 'Sucursal activada correctamente.'
            : 'Sucursal desactivada correctamente.';

        return back()->with('toast', ['type' => 'success', 'message' => $mensaje]);
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
