<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Controllers\Concerns\ExportaListado;
use App\Http\Requests\Areas\GuardarAreaRequest;
use App\Models\Area;
use App\Models\Empresa;
use App\Servicios\ServicioAuditoria;
use App\Soporte\ServicioGeneradorCodigos;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class AreaController extends Controller
{
    use ConEmpresa;
    use ExportaListado;

    public function __construct(
        private readonly ServicioAuditoria $auditoria,
        private readonly ServicioGeneradorCodigos $codigos,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Area::class);

        $usuario = $request->user();
        $empresaFiltro = $this->empresaDelFiltro($request);
        $filtros = $this->filtrosListado($request);

        $areas = $this->consultaAreas($request, $filtros)
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

    /**
     * Excel/PDF del listado, respetando los mismos filtros que `index()`.
     */
    public function exportar(Request $request): BinaryFileResponse|HttpResponse
    {
        $this->authorize('viewAny', Area::class);

        $filtros = $this->filtrosListado($request);
        $areas = $this->consultaAreas($request, $filtros)->get();

        $filas = $areas->map(fn (Area $a): array => [
            $a->nombre,
            $a->codigo,
            $a->descripcion,
            $a->activa ? 'Activa' : 'Inactiva',
            $a->empresa?->nombre_comercial,
            (int) $a->colaboradores_count,
            (int) $a->colaboradores_activos_count,
        ])->all();

        return $this->respuestaExportacion($request->input('formato', 'xlsx'), $filas, [
            'Nombre', 'Código', 'Descripción', 'Estado', 'Empresa', 'Colaboradores (total)', 'Colaboradores activos',
        ], 'Áreas');
    }

    /**
     * @return array<string, mixed>
     */
    private function filtrosListado(Request $request): array
    {
        return $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', 'in:activas,inactivas'],
            'orden' => ['nullable', 'in:az,za'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Builder<Area>
     */
    private function consultaAreas(Request $request, array $filtros): Builder
    {
        $idsAutorizadas = $this->idsEmpresasAutorizadas($request);
        $empresaFiltro = $this->empresaDelFiltro($request);
        $orden = ($filtros['orden'] ?? 'az') === 'za' ? 'desc' : 'asc';

        return Area::query()
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
            ->orderBy('nombre', $orden);
    }

    /**
     * Búsqueda con autocompletado de áreas de UNA empresa (BuscadorAsync,
     * p. ej. alta de colaborador). Requiere `empresa_id`.
     */
    public function buscar(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Area::class);

        $empresa = $this->empresaDelFiltro($request);

        if ($empresa === null) {
            return response()->json(['areas' => []]);
        }

        $termino = trim((string) $request->query('q', ''));

        $areas = Area::query()
            ->where('empresa_id', $empresa->id)
            ->where('activa', true)
            ->when($termino !== '', fn (Builder $q) => $q->where('nombre', 'like', "%{$termino}%"))
            ->orderBy('nombre')
            ->limit(20)
            ->get(['id', 'nombre']);

        return response()->json(['areas' => $areas]);
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
        $datos['codigo'] = ($datos['codigo'] ?? null) ?: $this->generarCodigo($empresa);

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
            'valores_anteriores' => ['activa' => ! $area->activa],
            'valores_nuevos' => ['activa' => $area->activa],
        ]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $area->activa ? 'Área activada correctamente.' : 'Área desactivada correctamente.',
        ]);
    }

    /**
     * Genera un código consecutivo y único dentro de la empresa (ARE-0001,
     * ARE-0002, …) cuando el usuario no captura uno. Race-safe:
     * `ServicioGeneradorCodigos` bloquea el contador dentro de una
     * transacción (nunca `count() + 1` sin lock).
     */
    private function generarCodigo(Empresa $empresa): string
    {
        return $this->codigos->siguienteConPrefijo($empresa, 'area', 'ARE');
    }
}
