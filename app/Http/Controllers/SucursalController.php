<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConEmpresaActiva;
use App\Models\Sucursal;
use App\Servicios\ServicioAuditoria;
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

        $sucursales = Sucursal::query()
            ->where('empresa_id', $empresa->id)
            ->withCount('colaboradores')
            ->orderBy('nombre')
            ->paginate($this->porPagina())
            ->through(fn (Sucursal $s): array => [
                'id' => $s->id,
                'codigo' => $s->codigo,
                'nombre' => $s->nombre,
                'direccion' => $s->direccion,
                'telefono' => $s->telefono,
                'activa' => $s->activa,
                'colaboradores' => $s->colaboradores_count,
            ]);

        return Inertia::render('Sucursales/Index', [
            'sucursales' => $sucursales,
            'permisos' => [
                'crear' => $request->user()->can('create', Sucursal::class),
                'editar' => $request->user()->can('sucursales.editar'),
                'desactivar' => $request->user()->can('sucursales.desactivar'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Sucursal::class);
        $empresa = $this->empresaActiva();

        $datos = $this->validar($request, $empresa->id, null);

        $sucursal = Sucursal::query()->create([...$datos, 'empresa_id' => $empresa->id, 'activa' => true]);

        $this->auditoria->registrar('sucursales', 'crear', [
            'tipo_entidad' => Sucursal::class, 'entidad_id' => $sucursal->id, 'sucursal_id' => $sucursal->id,
            'descripcion' => 'Alta de sucursal '.$sucursal->nombre,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Sucursal creada.']);
    }

    public function update(Request $request, Sucursal $sucursal): RedirectResponse
    {
        $this->authorize('update', $sucursal);
        abort_unless($sucursal->empresa_id === $this->empresaActiva()->id, 404);

        $sucursal->update($this->validar($request, $sucursal->empresa_id, $sucursal->id));

        $this->auditoria->registrar('sucursales', 'editar', [
            'tipo_entidad' => Sucursal::class, 'entidad_id' => $sucursal->id, 'sucursal_id' => $sucursal->id,
            'descripcion' => 'Edición de sucursal '.$sucursal->nombre,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Sucursal actualizada.']);
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

        return back()->with('toast', ['type' => 'success', 'message' => $sucursal->activa ? 'Sucursal activada.' : 'Sucursal desactivada.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, int $empresaId, ?int $sucursalId): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'codigo' => ['required', 'string', 'max:60', 'alpha_dash', Rule::unique('sucursales', 'codigo')->where(fn ($q) => $q->where('empresa_id', $empresaId))->ignore($sucursalId)],
            'direccion' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:40'],
        ], ['codigo.unique' => 'Ese código de sucursal ya existe en la empresa.']);
    }
}
