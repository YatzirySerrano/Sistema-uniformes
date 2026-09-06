<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Controllers\Concerns\ExportaListado;
use App\Http\Controllers\Concerns\ReactivaSuspendidos;
use App\Http\Requests\Empresas\GuardarEmpresaRequest;
use App\Models\Activo;
use App\Models\Area;
use App\Models\Colaborador;
use App\Models\Conjunto;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioCascadaSuspension;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class EmpresaController extends Controller
{
    use ConEmpresa;
    use ExportaListado;
    use ReactivaSuspendidos;

    public function __construct(
        private readonly ServicioAuditoria $auditoria,
        private readonly ServicioCascadaSuspension $cascada,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Empresa::class);

        $usuario = $request->user();
        $filtros = $this->filtrosListado($request);

        $empresas = $this->consultaEmpresas($request, $filtros)
            ->paginate($this->porPagina())
            ->withQueryString()
            ->through(fn (Empresa $e): array => [
                'id' => $e->id,
                'codigo' => $e->codigo,
                'nombre_comercial' => $e->nombre_comercial,
                'razon_social' => $e->razon_social,
                'rfc' => $e->rfc,
                'telefono' => $e->telefono,
                'correo' => $e->correo,
                'direccion' => $e->direccion,
                'activa' => $e->activa,
                'sucursales_activas' => (int) $e->sucursales_activas_count,
                'colaboradores_activos' => (int) $e->colaboradores_activos_count,
                'logo_url' => $this->logoUrl($e),
            ]);

        return Inertia::render('Empresas/Index', [
            'empresas' => $empresas,
            'filtros' => [
                'buscar' => $filtros['buscar'] ?? '',
                'estado' => $filtros['estado'] ?? '',
                'sucursales' => $filtros['sucursales'] ?? '',
                'colaboradores' => $filtros['colaboradores'] ?? '',
                'orden' => $filtros['orden'] ?? 'az',
            ],
            'puedeCrear' => $usuario->can('create', Empresa::class),
            'puedeEditar' => $usuario->can('empresas.editar') || $usuario->can('configuracion-empresa.editar'),
        ]);
    }

    /**
     * Excel/PDF del listado, respetando los mismos filtros que `index()` —
     * misma consulta, sólo cambia la salida (`?formato=xlsx|pdf`).
     */
    public function exportar(Request $request): BinaryFileResponse|HttpResponse
    {
        $this->authorize('viewAny', Empresa::class);

        $filtros = $this->filtrosListado($request);
        $empresas = $this->consultaEmpresas($request, $filtros)->get();

        $filas = $empresas->map(fn (Empresa $e): array => [
            $e->codigo,
            $e->nombre_comercial,
            $e->razon_social,
            $e->rfc,
            $e->telefono,
            $e->correo,
            $e->direccion,
            $e->activa ? 'Activa' : 'Inactiva',
            (int) $e->sucursales_activas_count,
            (int) $e->colaboradores_activos_count,
        ])->all();

        return $this->respuestaExportacion($request->input('formato', 'xlsx'), $filas, [
            'Código', 'Nombre comercial', 'Razón social', 'RFC', 'Teléfono', 'Correo',
            'Dirección', 'Estado', 'Sucursales activas', 'Colaboradores activos',
        ], 'Empresas');
    }

    /**
     * @return array<string, mixed>
     */
    private function filtrosListado(Request $request): array
    {
        return $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', Rule::in(['activas', 'inactivas'])],
            'sucursales' => ['nullable', Rule::in(['con', 'sin'])],
            'colaboradores' => ['nullable', Rule::in(['con', 'sin'])],
            'orden' => ['nullable', Rule::in(['az', 'za'])],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Builder<Empresa>
     */
    private function consultaEmpresas(Request $request, array $filtros): Builder
    {
        $usuario = $request->user();
        $orden = ($filtros['orden'] ?? 'az') === 'za' ? 'desc' : 'asc';

        // Superadministrador y Administrador ven todas las empresas de la
        // plataforma; los roles restringidos, sólo las de `empresa_usuario`.
        $base = $usuario->tieneAlcanceGlobal()
            ? Empresa::query()
            : $usuario->empresas()->getQuery();

        return $base
            ->withCount(['sucursalesActivas', 'colaboradoresActivos'])
            ->when($filtros['buscar'] ?? null, function (Builder $q, string $buscar): void {
                $q->where(function (Builder $sub) use ($buscar): void {
                    $sub->where('nombre_comercial', 'like', "%{$buscar}%")
                        ->orWhere('razon_social', 'like', "%{$buscar}%")
                        ->orWhere('codigo', 'like', "%{$buscar}%")
                        ->orWhere('rfc', 'like', "%{$buscar}%");
                });
            })
            ->when(($filtros['estado'] ?? null) === 'activas', fn (Builder $q) => $q->where('activa', true))
            ->when(($filtros['estado'] ?? null) === 'inactivas', fn (Builder $q) => $q->where('activa', false))
            ->when(($filtros['sucursales'] ?? null) === 'con', fn (Builder $q) => $q->has('sucursalesActivas'))
            ->when(($filtros['sucursales'] ?? null) === 'sin', fn (Builder $q) => $q->doesntHave('sucursalesActivas'))
            ->when(($filtros['colaboradores'] ?? null) === 'con', fn (Builder $q) => $q->has('colaboradoresActivos'))
            ->when(($filtros['colaboradores'] ?? null) === 'sin', fn (Builder $q) => $q->doesntHave('colaboradoresActivos'))
            ->orderBy('nombre_comercial', $orden);
    }

    /**
     * Búsqueda con autocompletado de empresas autorizadas para los combobox de
     * formularios y filtros (BuscadorAsync). Respeta el alcance del usuario.
     */
    public function buscar(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Empresa::class);

        $termino = trim((string) $request->query('q', ''));

        $empresas = $this->empresasAutorizadas($request)
            // Sólo empresas ACTIVAS: no tiene sentido operar (entregas,
            // colaboradores, altas nuevas…) contra una empresa desactivada.
            ->filter(fn (Empresa $e): bool => $e->activa)
            ->when($termino !== '', fn ($c) => $c->filter(fn (Empresa $e): bool => str_contains(
                Str::lower($e->nombre_comercial.' '.$e->codigo.' '.$e->razon_social),
                Str::lower($termino),
            )))
            ->take(20)
            ->map(fn (Empresa $e): array => [
                'id' => $e->id,
                'codigo' => $e->codigo,
                'nombre_comercial' => $e->nombre_comercial,
            ])->values();

        return response()->json(['empresas' => $empresas]);
    }

    public function show(Request $request, Empresa $empresa): Response
    {
        $this->authorize('view', $empresa);

        $empresa->loadCount([
            'sucursales',
            'sucursalesActivas',
            'colaboradores',
            'colaboradoresActivos',
        ]);

        return Inertia::render('Empresas/Detalle', [
            'empresa' => [
                ...$empresa->only([
                    'id', 'codigo', 'nombre_comercial', 'razon_social', 'rfc',
                    'telefono', 'correo', 'direccion', 'activa',
                ]),
                'logo_url' => $this->logoUrl($empresa),
                'sucursales_total' => (int) $empresa->sucursales_count,
                'sucursales_activas' => (int) $empresa->sucursales_activas_count,
                'colaboradores_total' => (int) $empresa->colaboradores_count,
                'colaboradores_activos' => (int) $empresa->colaboradores_activos_count,
            ],
            'puedeEditar' => $request->user()->can('update', $empresa),
            'puedeCambiarEstado' => $request->user()->can('cambiarEstado', $empresa),
            'suspendidos' => $this->cascada->paraVista($this->cascada->checklistDe($empresa)),
        ]);
    }

    /**
     * Reactivación selectiva (Fase 7): sólo levanta las suspensiones VIGENTES
     * causadas por ESTA empresa cuyo id venga marcado, y sólo si la entidad ya
     * no tiene otra dependencia obligatoria inactiva (blindaje multicausa) —
     * nunca revive algo inactivo por otra causa ni deja un estado a medias.
     */
    public function reactivarSuspendidos(Request $request, Empresa $empresa): RedirectResponse
    {
        $this->authorize('cambiarEstado', $empresa);

        $ids = array_map('intval', $request->input('ids', []));
        $resultado = $this->cascada->reactivarSeleccionados($empresa, $ids, $request->user()?->id);

        return back()->with('toast', $this->toastDeReactivacion($resultado));
    }

    public function store(GuardarEmpresaRequest $request): RedirectResponse
    {
        $datos = $request->validated();
        $datos['codigo'] = ($datos['codigo'] ?? null) ?: $this->generarCodigo($datos['nombre_comercial']);

        $empresa = Empresa::query()->create($datos);

        $this->auditoria->registrar('empresas', 'crear', [
            'empresa_id' => $empresa->id,
            'tipo_entidad' => Empresa::class,
            'entidad_id' => $empresa->id,
            'descripcion' => 'Alta de empresa '.$empresa->nombre_comercial,
        ]);

        return to_route('empresas.index')
            ->with('toast', ['type' => 'success', 'message' => 'Empresa registrada correctamente.']);
    }

    public function update(GuardarEmpresaRequest $request, Empresa $empresa): RedirectResponse
    {
        $anteriores = $empresa->toArray();

        $empresa->fill($request->safe()->except('logo'));

        if ($request->hasFile('logo')) {
            if ($empresa->logo_ruta) {
                Storage::disk('public')->delete($empresa->logo_ruta);
            }
            $empresa->logo_ruta = $request->file('logo')->store("empresas/{$empresa->id}", 'public') ?: null;
        }

        $empresa->save();

        $this->auditoria->registrar('empresas', 'editar', [
            'empresa_id' => $empresa->id,
            'tipo_entidad' => Empresa::class,
            'entidad_id' => $empresa->id,
            'descripcion' => 'Edición de empresa '.$empresa->nombre_comercial,
            'valores_anteriores' => $anteriores,
            'valores_nuevos' => $empresa->toArray(),
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Empresa actualizada correctamente.']);
    }

    public function toggleEstado(Request $request, Empresa $empresa): RedirectResponse
    {
        $this->authorize('cambiarEstado', $empresa);

        $empresa->update(['activa' => ! $empresa->activa]);

        $mensaje = $empresa->activa
            ? 'Empresa activada correctamente.'
            : 'Empresa desactivada correctamente.';

        if (! $empresa->activa) {
            // Cascada NO destructiva: Sucursales/Colaboradores/Áreas/Activos/
            // Conjuntos de la empresa quedan suspendidos (nunca los Almacenes:
            // son N:M y pueden seguir abasteciendo a otras empresas). La
            // reactivación de cada uno es selectiva, ver `show()`.
            $realizadoPor = $request->user()?->id;
            $suspendidos = 0;
            $suspendidos += $this->cascada->suspender($empresa, Sucursal::query()->where('empresa_id', $empresa->id), 'activa', $realizadoPor);
            $suspendidos += $this->cascada->suspender($empresa, Colaborador::query()->where('empresa_id', $empresa->id), 'activo', $realizadoPor);
            $suspendidos += $this->cascada->suspender($empresa, Area::query()->where('empresa_id', $empresa->id), 'activa', $realizadoPor);
            $suspendidos += $this->cascada->suspender($empresa, Activo::query()->where('empresa_id', $empresa->id), 'activo', $realizadoPor);
            $suspendidos += $this->cascada->suspender($empresa, Conjunto::query()->where('empresa_id', $empresa->id), 'activo', $realizadoPor);

            if ($suspendidos > 0) {
                $mensaje .= " {$suspendidos} registro(s) dependiente(s) quedaron suspendidos por cascada.";
            }
        }

        $this->auditoria->registrar('empresas', $empresa->activa ? 'activar' : 'desactivar', [
            'valores_anteriores' => ['activa' => ! $empresa->activa],
            'valores_nuevos' => ['activa' => $empresa->activa],
            'empresa_id' => $empresa->id,
            'tipo_entidad' => Empresa::class,
            'entidad_id' => $empresa->id,
            'descripcion' => ($empresa->activa ? 'Activación' : 'Desactivación').' de empresa '.$empresa->nombre_comercial,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => $mensaje]);
    }

    private function logoUrl(Empresa $empresa): ?string
    {
        return $empresa->logo_ruta
            ? Storage::disk('public')->url($empresa->logo_ruta)
            : null;
    }

    private function generarCodigo(string $nombre): string
    {
        $base = Str::upper(Str::slug(Str::substr($nombre, 0, 6), ''));
        $base = $base !== '' ? $base : 'EMP';
        $n = 1;
        do {
            $codigo = $base.str_pad((string) $n, 2, '0', STR_PAD_LEFT);
            $n++;
        } while (Empresa::query()->where('codigo', $codigo)->exists());

        return $codigo;
    }
}
