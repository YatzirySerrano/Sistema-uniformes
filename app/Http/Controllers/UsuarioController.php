<?php

namespace App\Http\Controllers;

use App\Enums\RolSistema;
use App\Http\Controllers\Concerns\ConEmpresa;
use App\Models\Empresa;
use App\Models\User;
use App\Servicios\ServicioAuditoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UsuarioController extends Controller
{
    use ConEmpresa;

    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $empresasIds = $this->empresasGestionables($request->user());

        $usuarios = User::query()
            ->when(! $request->user()->esSuperadministrador(), fn ($q) => $q->whereHas('empresas', fn ($e) => $e->whereIn('empresas.id', $empresasIds)))
            ->with(['roles:id,name', 'empresas:id,nombre_comercial'])
            ->orderBy('name')
            ->paginate($this->porPagina())
            ->through(fn (User $u): array => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'activo' => $u->activo,
                'verificado' => $u->email_verified_at !== null,
                'roles' => $u->roles->pluck('name'),
                'empresas' => $u->empresas->pluck('nombre_comercial'),
                'ultimo_acceso_en' => $u->ultimo_acceso_en?->toIso8601String(),
            ]);

        return Inertia::render('Usuarios/Index', [
            'usuarios' => $usuarios,
            'puedeCrear' => $request->user()->can('create', User::class),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', User::class);

        return Inertia::render('Usuarios/Formulario', [
            'usuario' => null,
            'roles' => $this->rolesAsignables($request->user()),
            'empresas' => $this->empresasAutorizadas($request)->map->only(['id', 'nombre_comercial'])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $datos = $this->validar($request, null);
        $this->validarEmpresas($request, $datos['empresas'] ?? []);

        $usuario = User::query()->create([
            'name' => $datos['name'],
            'email' => Str::lower($datos['email']),
            'password' => Hash::make($datos['password']),
            'activo' => $request->boolean('activo', true),
        ]);

        $usuario->syncRoles($this->filtrarRoles($request->user(), $datos['roles'] ?? []));
        $usuario->empresas()->sync($datos['empresas'] ?? []);
        $usuario->sucursales()->sync($datos['sucursales'] ?? []);

        $usuario->sendEmailVerificationNotification();

        $this->auditoria->registrar('usuarios', 'crear', [
            'tipo_entidad' => User::class, 'entidad_id' => $usuario->id,
            'descripcion' => 'Alta de usuario '.$usuario->email,
        ]);

        return to_route('usuarios.index')->with('toast', ['type' => 'success', 'message' => 'Usuario creado. Se envió el correo de verificación.']);
    }

    public function edit(Request $request, User $usuario): Response
    {
        $this->authorize('update', $usuario);

        return Inertia::render('Usuarios/Formulario', [
            'usuario' => [
                'id' => $usuario->id,
                'name' => $usuario->name,
                'email' => $usuario->email,
                'activo' => $usuario->activo,
                'roles' => $usuario->roles->pluck('name'),
                'empresas' => $usuario->empresas->pluck('id'),
                'sucursales' => $usuario->sucursales->pluck('id'),
            ],
            'roles' => $this->rolesAsignables($request->user()),
            'empresas' => $this->empresasAutorizadas($request)->map->only(['id', 'nombre_comercial'])->values(),
        ]);
    }

    public function update(Request $request, User $usuario): RedirectResponse
    {
        $this->authorize('update', $usuario);

        $datos = $this->validar($request, $usuario);
        $this->validarEmpresas($request, $datos['empresas'] ?? []);
        $anteriores = ['roles' => $usuario->getRoleNames(), 'empresas' => $usuario->empresas->pluck('id')];

        $usuario->fill([
            'name' => $datos['name'],
            'email' => Str::lower($datos['email']),
            'activo' => $request->boolean('activo', $usuario->activo),
        ]);

        if (! empty($datos['password'])) {
            $usuario->password = Hash::make($datos['password']);
        }

        $usuario->save();

        if ($request->user()->can('asignarRoles', $usuario)) {
            $usuario->syncRoles($this->filtrarRoles($request->user(), $datos['roles'] ?? []));
        }

        $usuario->empresas()->sync($datos['empresas'] ?? []);
        $usuario->sucursales()->sync($datos['sucursales'] ?? []);

        $this->auditoria->registrar('usuarios', 'editar', [
            'tipo_entidad' => User::class, 'entidad_id' => $usuario->id,
            'descripcion' => 'Edición de usuario '.$usuario->email,
            'valores_anteriores' => $anteriores,
            'valores_nuevos' => ['roles' => $usuario->getRoleNames(), 'empresas' => $usuario->empresas()->pluck('empresas.id')],
        ]);

        return to_route('usuarios.index')->with('toast', ['type' => 'success', 'message' => 'Usuario actualizado.']);
    }

    public function toggle(Request $request, User $usuario): RedirectResponse
    {
        $this->authorize('desactivar', $usuario);

        $usuario->update(['activo' => ! $usuario->activo]);

        $this->auditoria->registrar('usuarios', $usuario->activo ? 'activar' : 'desactivar', [
            'tipo_entidad' => User::class, 'entidad_id' => $usuario->id,
            'descripcion' => ($usuario->activo ? 'Activación' : 'Desactivación').' de usuario '.$usuario->email,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => $usuario->activo ? 'Usuario activado.' : 'Usuario desactivado.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, ?User $usuario): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($usuario?->id)],
            'password' => [$usuario === null ? 'required' : 'nullable', 'confirmed', Password::default()],
            'activo' => ['boolean'],
            'roles' => ['array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
            'empresas' => ['array'],
            'empresas.*' => ['integer', Rule::exists('empresas', 'id')],
            'sucursales' => ['array'],
            'sucursales.*' => ['integer', Rule::exists('sucursales', 'id')],
        ]);
    }

    /**
     * @param  array<int, int>  $empresas
     */
    private function validarEmpresas(Request $request, array $empresas): void
    {
        if ($request->user()->tieneAlcanceGlobal()) {
            return;
        }

        $permitidas = $this->empresasGestionables($request->user());

        abort_if(array_diff($empresas, $permitidas) !== [], 403, 'No puedes asignar empresas fuera de tu alcance.');
    }

    /**
     * @return array<int, int>
     */
    private function empresasGestionables(User $user): array
    {
        if ($user->tieneAlcanceGlobal()) {
            return Empresa::query()->pluck('id')->all();
        }

        return $user->empresas()->pluck('empresas.id')->all();
    }

    /**
     * @return array<int, array{name: string, etiqueta: string}>
     */
    private function rolesAsignables(User $user): array
    {
        return Role::query()->orderBy('name')->get()
            ->when(! $user->esSuperadministrador(), fn ($c) => $c->reject(fn (Role $r) => $r->name === RolSistema::Superadministrador->value))
            ->map(fn (Role $r): array => ['name' => $r->name, 'etiqueta' => Str::of($r->name)->replace('_', ' ')->title()->value()])
            ->values()->all();
    }

    /**
     * @param  array<int, string>  $roles
     * @return array<int, string>
     */
    private function filtrarRoles(User $user, array $roles): array
    {
        if ($user->esSuperadministrador()) {
            return $roles;
        }

        return array_values(array_filter($roles, fn (string $r): bool => $r !== RolSistema::Superadministrador->value));
    }
}
