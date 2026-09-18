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
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelPdf\Enums\Format;
use Spatie\LaravelPdf\Facades\Pdf;
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

        $roles = $this->consultaRoles($request, $filtros)->get()
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
     *
     * El PDF usa una plantilla propia (`reportes.roles-permisos`, NO la
     * genérica de una tabla): un rol con muchos permisos como una sola celda
     * de texto corrido es ilegible, así que aquí cada rol es un bloque con
     * sus permisos en listas por categoría. El Excel sí puede ser una fila
     * por rol (una celda ancha se lee bien en una hoja de cálculo).
     */
    public function exportar(Request $request): BinaryFileResponse|HttpResponse
    {
        abort_unless($request->user()->can('roles.ver'), 403);

        $filtros = $this->filtrosListado($request);
        $roles = $this->consultaRoles($request, $filtros)->get();

        $filtrosHumanos = array_filter([
            'Búsqueda' => $filtros['buscar'] ?? null,
            'Tipo' => match ($filtros['tipo'] ?? null) {
                'base' => 'Roles base del sistema',
                'personalizados' => 'Roles personalizados',
                default => null,
            },
        ]);

        $contexto = new ContextoExportacion(
            'Roles y permisos',
            null,
            $filtrosHumanos,
            $roles->count(),
            generadoPor: $request->user()?->name,
        );

        if ($request->input('formato', 'xlsx') === 'pdf') {
            $pdf = Pdf::view('reportes.roles-permisos', [
                'contexto' => $contexto,
                'roles' => $roles->map(fn (Role $r): array => $this->rolParaPdf($r))->all(),
            ])
                ->format(Format::Letter)
                ->portrait()
                ->margins(10, 10, 16, 10)
                ->footerView('reportes._pie');

            return response($pdf->generatePdfContent(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$contexto->nombreArchivo().'.pdf"',
            ]);
        }

        $filas = $roles->map(function (Role $r): array {
            $permisos = $r->permissions->pluck('name');
            $esBase = in_array($r->name, RolSistema::valores(), true);

            return [
                Str::of($r->name)->replace('_', ' ')->title()->value(),
                $esBase ? 'Base del sistema' : 'Personalizado',
                (int) $r->users_count,
                $permisos->count(),
                $this->resumenPermisos($permisos->all()),
            ];
        })->all();

        return $this->respuestaExportacion('xlsx', $filas, [
            'Rol', 'Tipo', 'Usuarios', 'N.º de permisos', 'Permisos',
        ], $contexto);
    }

    /**
     * @return array{etiqueta: string, base: bool, usuarios: int, total_permisos: int, grupos: Collection<string, array<int, string>>}
     */
    private function rolParaPdf(Role $r): array
    {
        $permisos = $r->permissions->pluck('name')->all();

        return [
            'etiqueta' => Str::of($r->name)->replace('_', ' ')->title()->value(),
            'base' => in_array($r->name, RolSistema::valores(), true),
            'usuarios' => (int) $r->users_count,
            'total_permisos' => count($permisos),
            'grupos' => $this->agruparPermisos($permisos),
        ];
    }

    /**
     * Agrupa los permisos de un rol por categoría (mismo agrupado que ya usa
     * la pantalla de Roles en pantalla, `Permisos::GRUPOS`) para poder
     * imprimirlos como listas cortas por categoría en vez de un bloque
     * corrido. El orden de categorías es siempre el mismo (el de
     * `Permisos::GRUPOS`), nunca el de inserción de Spatie.
     *
     * @param  array<int, string>  $permisos
     * @return Collection<string, array<int, string>>
     */
    private function agruparPermisos(array $permisos): Collection
    {
        $permisosDelRol = array_flip($permisos);
        $agrupado = collect();

        foreach (Permisos::GRUPOS as $grupo) {
            $items = [];
            foreach ($grupo['permisos'] as $clave => $etiqueta) {
                if (isset($permisosDelRol[$clave])) {
                    $items[] = $etiqueta;
                }
            }

            if ($items !== []) {
                $agrupado->put($grupo['etiqueta'], $items);
            }
        }

        return $agrupado;
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
    private function consultaRoles(Request $request, array $filtros): Builder
    {
        $base = RolSistema::valores();

        return Role::query()
            ->with('permissions:id,name')
            ->withCount('users')
            // El rol Superadministrador es exclusivo del equipo técnico: no
            // debe aparecer en el módulo de Roles y permisos para nadie más
            // (ni en el listado ni en la exportación).
            ->when(! $request->user()->esSuperadministrador(), fn (Builder $q) => $q->where('name', '!=', RolSistema::Superadministrador->value))
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
     * Representación legible de los permisos de un rol para la columna
     * "Permisos" del Excel: un bloque de texto POR MÓDULO (nombre en su
     * propia línea) con cada permiso en una línea aparte con viñeta `•`, y
     * una línea en blanco entre módulos — antes era una sola cadena unida
     * por " · " (`"Activos: Ver · Acuses: Ver, Firmar"`), ilegible en
     * cuanto un rol tenía varios módulos. Los saltos de línea los interpreta
     * `ajustarColumnasYAlineacion()` (`DecoraConContexto`) para activar
     * `wrapText` + alto de fila automático; ver `.ai/rules/sistema.md`.
     * Reutiliza `agruparPermisos()` (misma fuente que ya usa el PDF de este
     * export) para que el orden y las etiquetas de módulo sean IDÉNTICOS en
     * ambos formatos — nunca se reimplementa el agrupado aquí.
     *
     * @param  array<int, string>  $permisos
     */
    private function resumenPermisos(array $permisos): string
    {
        if ($permisos === []) {
            return 'Sin permisos';
        }

        $bloques = [];
        foreach ($this->agruparPermisos($permisos) as $etiquetaGrupo => $items) {
            $bloques[] = $etiquetaGrupo."\n".implode("\n", array_map(fn (string $item): string => "• {$item}", $items));
        }

        return implode("\n\n", $bloques);
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
