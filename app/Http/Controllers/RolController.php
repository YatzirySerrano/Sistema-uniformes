<?php

namespace App\Http\Controllers;

use App\Enums\RolSistema;
use App\Servicios\ServicioAuditoria;
use App\Soporte\Permisos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class RolController extends Controller
{
    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('roles.ver'), 403);

        $roles = Role::query()->with('permissions:id,name')->withCount('users')->orderBy('name')->get()
            ->map(fn (Role $r): array => [
                'id' => $r->id,
                'name' => $r->name,
                'etiqueta' => Str::of($r->name)->replace('_', ' ')->title()->value(),
                'base' => in_array($r->name, RolSistema::valores(), true),
                'usuarios' => $r->users_count,
                'permisos' => $r->permissions->pluck('name'),
            ]);

        return Inertia::render('Roles/Index', [
            'roles' => $roles,
            'gruposPermisos' => Permisos::GRUPOS,
            'permisos' => [
                'crear' => $request->user()->can('roles.crear'),
                'editar' => $request->user()->can('roles.editar'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('roles.crear'), 403);

        $datos = $request->validate([
            'name' => ['required', 'string', 'max:80', 'regex:/^[\pL0-9 _-]+$/u', Rule::unique('roles', 'name')],
            'permisos' => ['array'],
            'permisos.*' => ['string', Rule::in(Permisos::todos())],
        ], ['name.unique' => 'Ya existe un rol con ese nombre.']);

        $rol = Role::create(['name' => $datos['name'], 'guard_name' => 'web']);
        $rol->syncPermissions($datos['permisos'] ?? []);

        $this->auditoria->registrar('roles', 'crear', [
            'tipo_entidad' => Role::class, 'entidad_id' => $rol->id,
            'descripcion' => 'Creación del rol '.$rol->name,
            'valores_nuevos' => ['permisos' => $datos['permisos'] ?? []],
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Rol creado.']);
    }

    public function update(Request $request, Role $rol): RedirectResponse
    {
        abort_unless($request->user()->can('roles.editar'), 403);
        $esBase = in_array($rol->name, RolSistema::valores(), true);
        abort_if($rol->name === RolSistema::Superadministrador->value, 403, 'El rol Superadministrador no puede modificarse.');

        $datos = $request->validate([
            'name' => ['required', 'string', 'max:80', 'regex:/^[\pL0-9 _-]+$/u', Rule::unique('roles', 'name')->ignore($rol->id)],
            'permisos' => ['array'],
            'permisos.*' => ['string', Rule::in(Permisos::todos())],
        ]);

        $anteriores = $rol->permissions->pluck('name');

        if (! $esBase) {
            $rol->update(['name' => $datos['name']]);
        }

        $rol->syncPermissions($datos['permisos'] ?? []);

        $this->auditoria->registrar('roles', 'editar', [
            'tipo_entidad' => Role::class, 'entidad_id' => $rol->id,
            'descripcion' => 'Edición del rol '.$rol->name,
            'valores_anteriores' => ['permisos' => $anteriores],
            'valores_nuevos' => ['permisos' => $datos['permisos'] ?? []],
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Rol actualizado.']);
    }

    public function destroy(Request $request, Role $rol): RedirectResponse
    {
        abort_unless($request->user()->can('roles.editar'), 403);
        abort_if(in_array($rol->name, RolSistema::valores(), true), 403, 'Los roles base del sistema no pueden eliminarse.');
        abort_if($rol->users()->exists(), 422, 'No se puede eliminar un rol con usuarios asignados.');

        $nombre = $rol->name;
        $rol->delete();

        $this->auditoria->registrar('roles', 'eliminar', ['descripcion' => 'Eliminación del rol '.$nombre]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Rol eliminado.']);
    }
}
