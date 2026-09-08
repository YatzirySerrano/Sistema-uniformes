<?php

namespace App\Http\Controllers;

use App\Enums\RolSistema;
use App\Http\Controllers\Concerns\ExportaListado;
use App\Servicios\ServicioAuditoria;
use App\Soporte\ContextoExportacion;
use App\Soporte\Permisos;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class RolController extends Controller
{
    use ExportaListado;

    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('roles.ver'), 403);

        $filtros = $this->filtrosListado($request);

        $roles = $this->consultaRoles($filtros)->get()
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
            'filtros' => [
                'buscar' => $filtros['buscar'] ?? '',
                'tipo' => $filtros['tipo'] ?? '',
            ],
            'permisos' => [
                'crear' => $request->user()->can('roles.crear'),
                'editar' => $request->user()->can('roles.editar'),
            ],
        ]);
    }

    /**
     * Excel/PDF del listado de roles, respetando los mismos filtros que
     * `index()`. Representación legible: nombre, tipo, nº de usuarios,
     * nº de permisos y permisos agrupados por módulo (nunca ids técnicos).
     */
    public function exportar(Request $request): BinaryFileResponse|HttpResponse
    {
        abort_unless($request->user()->can('roles.ver'), 403);

        $filtros = $this->filtrosListado($request);
        $roles = $this->consultaRoles($filtros)->get();

        $etiquetasPermiso = Permisos::etiquetas();

        $filas = $roles->map(function (Role $r) use ($etiquetasPermiso): array {
            $permisos = $r->permissions->pluck('name');
            $esBase = in_array($r->name, RolSistema::valores(), true);

            return [
                Str::of($r->name)->replace('_', ' ')->title()->value(),
                $esBase ? 'Base del sistema' : 'Personalizado',
                (int) $r->users_count,
                $permisos->count(),
                $this->resumenPermisos($permisos->all(), $etiquetasPermiso),
            ];
        })->all();

        $filtrosHumanos = array_filter([
            'Búsqueda' => $filtros['buscar'] ?? null,
            'Tipo' => match ($filtros['tipo'] ?? null) {
                'base' => 'Roles base del sistema',
                'personalizados' => 'Roles personalizados',
                default => null,
            },
        ]);

        $contexto = new ContextoExportacion('Roles y permisos', null, $filtrosHumanos, $roles->count());

        return $this->respuestaExportacion($request->input('formato', 'xlsx'), $filas, [
            'Rol', 'Tipo', 'Usuarios', 'N.º de permisos', 'Permisos',
        ], $contexto);
    }

    /**
     * @return array<string, mixed>
     */
    private function filtrosListado(Request $request): array
    {
        return $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'tipo' => ['nullable', 'in:base,personalizados'],
        ]);
    }

    /**
     * Consulta filtrada compartida por `index()` y `exportar()`.
     *
     * @param  array<string, mixed>  $filtros
     * @return Builder<Role>
     */
    private function consultaRoles(array $filtros): Builder
    {
        $base = RolSistema::valores();

        return Role::query()
            ->with('permissions:id,name')
            ->withCount('users')
            ->when(($filtros['tipo'] ?? null) === 'base', fn (Builder $q) => $q->whereIn('name', $base))
            ->when(($filtros['tipo'] ?? null) === 'personalizados', fn (Builder $q) => $q->whereNotIn('name', $base))
            ->when($filtros['buscar'] ?? null, function (Builder $q, string $buscar): void {
                $q->where(function (Builder $sub) use ($buscar): void {
                    $sub->where('name', 'like', "%{$buscar}%")
                        ->orWhereHas('permissions', fn (Builder $p) => $p->where('name', 'like', "%{$buscar}%"));
                });
            })
            ->orderBy('name');
    }

    /**
     * Agrupa los permisos de un rol por módulo, ya humanizados
     * ("Empresas: Ver, Crear · Activos: Ver"), para que el reporte quede
     * legible en vez de una lista interminable de claves técnicas.
     *
     * @param  array<int, string>  $permisos
     * @param  array<string, string>  $etiquetas
     */
    private function resumenPermisos(array $permisos, array $etiquetas): string
    {
        if ($permisos === []) {
            return 'Sin permisos';
        }

        $porGrupo = [];
        foreach ($permisos as $permiso) {
            $grupo = str_contains($permiso, '.') ? Str::before($permiso, '.') : $permiso;
            $porGrupo[$grupo][] = $etiquetas[$permiso] ?? $permiso;
        }

        $partes = [];
        foreach ($porGrupo as $grupo => $items) {
            $partes[] = Str::of($grupo)->replace('-', ' ')->title()->value().': '.implode(', ', $items);
        }

        return implode(' · ', $partes);
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
