<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Controllers\Concerns\ExportaListado;
use App\Http\Requests\Colaboradores\GuardarColaboradorRequest;
use App\Models\Area;
use App\Models\Colaborador;
use App\Models\Sucursal;
use App\Servicios\ServicioAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Colaboradores por empresa. La empresa llega como filtro (listado) o campo
 * `empresa_id` (alta); en edición queda fijada por el registro. Sucursales y
 * áreas del formulario se acotan a la empresa elegida y al alcance del usuario.
 */
class ColaboradorController extends Controller
{
    use ConEmpresa;
    use ExportaListado;

    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Colaborador::class);

        $usuario = $request->user();
        $empresaFiltro = $this->empresaDelFiltro($request);
        $filtros = $this->filtrosListado($request);

        $colaboradores = $this->consultaColaboradores($request, $filtros)
            ->paginate($this->porPagina())
            ->withQueryString()
            ->through(fn (Colaborador $c): array => [
                'id' => $c->id,
                'numero_empleado' => $c->numero_empleado,
                'nombre_completo' => $c->nombre_completo,
                'puesto' => $c->puesto,
                'area' => $c->area,
                'activo' => $c->activo,
                'foto_url' => $c->foto_ruta !== null ? route('colaboradores.foto', $c) : null,
                'sucursal' => $c->sucursal === null ? null : ['nombre' => $c->sucursal->nombre],
                'empresa' => $c->empresa === null ? null : ['id' => $c->empresa->id, 'nombre_comercial' => $c->empresa->nombre_comercial],
            ]);

        return Inertia::render('Colaboradores/Index', [
            'colaboradores' => $colaboradores,
            'filtros' => [...$filtros, 'empresa_id' => $empresaFiltro?->id],
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
            'sucursales' => $empresaFiltro !== null
                ? $this->acceso()->sucursalesAutorizadas($usuario, $empresaFiltro)->map->only(['id', 'nombre'])->values()
                : [],
            'areas' => $empresaFiltro !== null ? $this->areasDe($empresaFiltro->id) : [],
            'puedeCrear' => $usuario->can('create', Colaborador::class),
            'puedeImportar' => $usuario->can('importar', Colaborador::class),
            'puedeVerEliminados' => $usuario->can('colaboradores.desactivar'),
        ]);
    }

    /**
     * Excel/PDF del listado, respetando los mismos filtros que `index()`.
     */
    public function exportar(Request $request): BinaryFileResponse|HttpResponse
    {
        $this->authorize('viewAny', Colaborador::class);

        $filtros = $this->filtrosListado($request);
        $colaboradores = $this->consultaColaboradores($request, $filtros)->get();

        $filas = $colaboradores->map(fn (Colaborador $c): array => [
            $c->numero_empleado,
            $c->nombre_completo,
            $c->puesto,
            $c->area,
            $c->correo,
            $c->empresa?->nombre_comercial,
            $c->sucursal?->nombre,
            $c->activo ? 'Activo' : 'Inactivo',
        ])->all();

        return $this->respuestaExportacion($request->input('formato', 'xlsx'), $filas, [
            'N.º empleado', 'Nombre completo', 'Puesto', 'Área', 'Correo', 'Empresa', 'Sucursal', 'Estado',
        ], 'Colaboradores');
    }

    /**
     * @return array<string, mixed>
     */
    private function filtrosListado(Request $request): array
    {
        return $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'sucursal_id' => ['nullable', 'integer'],
            'area_id' => ['nullable', 'integer'],
            'estado' => ['nullable', 'in:activos,inactivos,todos'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Builder<Colaborador>
     */
    private function consultaColaboradores(Request $request, array $filtros): Builder
    {
        $usuario = $request->user();
        $idsAutorizadas = $this->idsEmpresasAutorizadas($request);
        $empresaFiltro = $this->empresaDelFiltro($request);

        // Sólo quien puede desactivar colaboradores puede verlos eliminados
        // en el listado (ni siquiera dentro de "todos"). Para el resto,
        // "activo" se fuerza sin importar qué `estado` pida la URL.
        $puedeVerEliminados = $usuario->can('colaboradores.desactivar');

        $sucursalesVisibles = $idsAutorizadas
            ->flatMap(fn (int $id): array => $this->acceso()->sucursalesAutorizadas($usuario, $id)->pluck('id')->all())
            ->unique()->values();

        return Colaborador::query()
            ->whereIn('empresa_id', $idsAutorizadas)
            ->whereIn('sucursal_id', $sucursalesVisibles)
            ->when($empresaFiltro !== null, fn (Builder $q) => $q->where('empresa_id', $empresaFiltro->id))
            ->when($filtros['buscar'] ?? null, fn (Builder $q, $b) => $q->where(fn (Builder $s) => $s
                ->where('nombre_completo', 'like', "%{$b}%")
                ->orWhere('numero_empleado', 'like', "%{$b}%")))
            ->when($filtros['sucursal_id'] ?? null, fn (Builder $q, $s) => $q->where('sucursal_id', $s))
            ->when($filtros['area_id'] ?? null, fn (Builder $q, $a) => $q->where('area_id', $a))
            ->when(! $puedeVerEliminados, fn (Builder $q) => $q->where('activo', true))
            ->when($puedeVerEliminados && ($filtros['estado'] ?? null) === 'activos', fn (Builder $q) => $q->where('activo', true))
            ->when($puedeVerEliminados && ($filtros['estado'] ?? null) === 'inactivos', fn (Builder $q) => $q->where('activo', false))
            ->with(['sucursal:id,nombre', 'empresa:id,nombre_comercial'])
            ->orderBy('nombre_completo');
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Colaborador::class);

        // Preselección desde ?sucursal_id (p. ej. al llegar desde una sucursal):
        // sólo se respeta si el usuario tiene acceso a esa sucursal; se
        // preselecciona también su empresa.
        $sucursalId = (int) $request->input('sucursal_id');
        $preseleccion = null;

        if ($sucursalId > 0) {
            $sucursal = Sucursal::query()->find($sucursalId);

            if ($sucursal !== null && $request->user()->puedeAccederSucursal($sucursal)) {
                $preseleccion = ['sucursal' => ['id' => $sucursal->id, 'nombre' => $sucursal->nombre], 'empresa_id' => $sucursal->empresa_id];
            }
        }

        return Inertia::render('Colaboradores/Formulario', [
            'colaborador' => null,
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
            'sucursalPreseleccionada' => $preseleccion['sucursal'] ?? null,
            'empresaPreseleccionadaId' => $preseleccion['empresa_id'] ?? null,
        ]);
    }

    public function store(GuardarColaboradorRequest $request): RedirectResponse
    {
        $empresa = $request->empresaResuelta();

        $colaborador = Colaborador::query()->create([
            ...$request->safe()->except(['activo', 'empresa_id', 'foto']),
            'empresa_id' => $empresa->id,
            'area' => $this->nombreAreaEspejo($empresa->id, $request->integer('area_id') ?: null, $request->input('area')),
            'foto_ruta' => $request->hasFile('foto') ? $request->file('foto')->store("colaboradores/{$empresa->id}", 'local') ?: null : null,
            'activo' => $request->boolean('activo', true),
        ]);

        $this->auditoria->registrar('colaboradores', 'crear', [
            'tipo_entidad' => Colaborador::class, 'entidad_id' => $colaborador->id, 'empresa_id' => $empresa->id,
            'descripcion' => 'Alta de colaborador '.$colaborador->nombre_completo,
            'valores_nuevos' => $colaborador->toArray(),
        ]);

        return to_route('colaboradores.index')->with('toast', ['type' => 'success', 'message' => 'Colaborador registrado.']);
    }

    public function edit(Request $request, Colaborador $colaborador): Response
    {
        $this->authorize('update', $colaborador);

        $colaborador->load(['sucursal:id,nombre', 'departamento:id,nombre']);

        return Inertia::render('Colaboradores/Formulario', [
            'colaborador' => [
                ...$colaborador->only(['id', 'empresa_id', 'numero_empleado', 'nombre_completo', 'sucursal_id', 'puesto', 'area', 'area_id', 'correo', 'activo']),
                'sucursal' => $colaborador->sucursal === null ? null : ['id' => $colaborador->sucursal->id, 'nombre' => $colaborador->sucursal->nombre],
                'area_actual' => $colaborador->departamento === null ? null : ['id' => $colaborador->departamento->id, 'nombre' => $colaborador->departamento->nombre],
                'foto_url' => $colaborador->foto_ruta !== null ? route('colaboradores.foto', $colaborador) : null,
            ],
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
        ]);
    }

    public function show(Request $request, Colaborador $colaborador): Response
    {
        $this->authorize('view', $colaborador);

        $colaborador->load(['sucursal:id,nombre', 'departamento:id,nombre', 'empresa:id,nombre_comercial']);

        return Inertia::render('Colaboradores/Detalle', [
            'colaborador' => [
                ...$colaborador->only(['id', 'numero_empleado', 'nombre_completo', 'puesto', 'correo', 'activo']),
                'empresa' => $colaborador->empresa?->nombre_comercial,
                'sucursal' => $colaborador->sucursal?->nombre,
                'area' => $colaborador->departamento === null ? $colaborador->area : $colaborador->departamento->nombre,
                'foto_url' => $colaborador->foto_ruta !== null ? route('colaboradores.foto', $colaborador) : null,
            ],
            'puedeEditar' => $request->user()->can('update', $colaborador),
            'puedeEliminar' => $request->user()->can('desactivar', $colaborador),
            'puedeVerExpediente' => $request->user()->can('verExpediente', $colaborador),
        ]);
    }

    public function foto(Colaborador $colaborador): StreamedResponse
    {
        $this->authorize('view', $colaborador);

        abort_unless($colaborador->foto_ruta !== null && Storage::disk('local')->exists($colaborador->foto_ruta), 404);

        return Storage::disk('local')->response($colaborador->foto_ruta);
    }

    public function update(GuardarColaboradorRequest $request, Colaborador $colaborador): RedirectResponse
    {
        $anteriores = $colaborador->toArray();

        $colaborador->update([
            ...$request->safe()->except(['activo', 'empresa_id', 'foto']),
            'area' => $this->nombreAreaEspejo($colaborador->empresa_id, $request->integer('area_id') ?: null, $request->input('area')),
            'foto_ruta' => $request->hasFile('foto') ? $this->reemplazarFoto($colaborador, $request) : $colaborador->foto_ruta,
            'activo' => $request->boolean('activo', $colaborador->activo),
        ]);

        $this->auditoria->registrar('colaboradores', 'editar', [
            'tipo_entidad' => Colaborador::class, 'entidad_id' => $colaborador->id, 'empresa_id' => $colaborador->empresa_id,
            'descripcion' => 'Edición de colaborador '.$colaborador->nombre_completo,
            'valores_anteriores' => $anteriores,
            'valores_nuevos' => $colaborador->toArray(),
        ]);

        return to_route('colaboradores.index')->with('toast', ['type' => 'success', 'message' => 'Colaborador actualizado.']);
    }

    public function toggle(Colaborador $colaborador): RedirectResponse
    {
        $this->authorize('desactivar', $colaborador);

        $colaborador->update(['activo' => ! $colaborador->activo]);

        $this->auditoria->registrar('colaboradores', $colaborador->activo ? 'activar' : 'desactivar', [
            'tipo_entidad' => Colaborador::class, 'entidad_id' => $colaborador->id, 'empresa_id' => $colaborador->empresa_id,
            'descripcion' => ($colaborador->activo ? 'Activación' : 'Desactivación').' de '.$colaborador->nombre_completo,
            'valores_anteriores' => ['activo' => ! $colaborador->activo],
            'valores_nuevos' => ['activo' => $colaborador->activo],
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => $colaborador->activo ? 'Colaborador restaurado.' : 'Colaborador eliminado.']);
    }

    /**
     * Búsqueda con autocompletado para flujos donde la empresa se DERIVA del
     * colaborador (Entregas/Devoluciones): a diferencia de otros buscadores,
     * NO exige `empresa_id` — busca entre todas las empresas autorizadas del
     * usuario y devuelve la empresa/sucursal de cada resultado.
     */
    /**
     * Búsqueda server-side de colaboradores OPERATIVOS (nunca carga el
     * catálogo completo: `limit(20)` + término). Acotada SIEMPRE por el
     * alcance del usuario y, si vienen, por `empresa_id`/`sucursal_id`
     * (p. ej. el flujo de Entregas: Empresa → Sucursal → Colaborador) —
     * cualquiera de los dos que no pertenezca al alcance del usuario devuelve
     * una lista vacía en vez de filtrar silenciosamente.
     */
    public function buscar(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Colaborador::class);

        $idsAutorizadas = $this->idsEmpresasAutorizadas($request);
        $termino = trim((string) $request->query('q', ''));
        $usuario = $request->user();

        $empresaId = $request->filled('empresa_id') ? (int) $request->query('empresa_id') : null;
        $sucursalId = $request->filled('sucursal_id') ? (int) $request->query('sucursal_id') : null;

        if ($empresaId !== null && ! $idsAutorizadas->contains($empresaId)) {
            return response()->json(['colaboradores' => []]);
        }

        if ($sucursalId !== null) {
            $sucursal = Sucursal::query()->find($sucursalId);

            if ($sucursal === null
                || ! $idsAutorizadas->contains($sucursal->empresa_id)
                || ($empresaId !== null && $empresaId !== $sucursal->empresa_id)
                || ! $this->acceso()->sucursalesAutorizadas($usuario, $sucursal->empresa_id)->pluck('id')->contains($sucursalId)
            ) {
                return response()->json(['colaboradores' => []]);
            }
        }

        $colaboradores = Colaborador::query()
            ->whereIn('empresa_id', $idsAutorizadas)
            ->when($empresaId !== null, fn ($q) => $q->where('empresa_id', $empresaId))
            ->when($sucursalId !== null, fn ($q) => $q->where('sucursal_id', $sucursalId))
            ->where('activo', true)
            ->whereHas('empresa', fn ($q) => $q->where('activa', true))
            ->whereHas('sucursal', fn ($q) => $q->where('activa', true))
            ->when($termino !== '', fn ($q) => $q->where(fn ($sub) => $sub
                ->where('nombre_completo', 'like', "%{$termino}%")
                ->orWhere('numero_empleado', 'like', "%{$termino}%")))
            ->with(['empresa:id,nombre_comercial', 'sucursal:id,nombre'])
            ->orderBy('nombre_completo')
            ->limit(20)
            ->get()
            ->map(fn (Colaborador $c): array => [
                'id' => $c->id,
                'nombre_completo' => $c->nombre_completo,
                'numero_empleado' => $c->numero_empleado,
                'empresa_id' => $c->empresa_id,
                'empresa' => $c->empresa?->nombre_comercial,
                'sucursal_id' => $c->sucursal_id,
                'sucursal' => $c->sucursal?->nombre,
            ]);

        return response()->json(['colaboradores' => $colaboradores]);
    }

    /**
     * @return array<int, array{id: int, nombre: string}>
     */
    private function areasDe(int $empresaId): array
    {
        return Area::query()
            ->where('empresa_id', $empresaId)
            ->where('activa', true)
            ->orderBy('nombre')
            ->get(['id', 'nombre'])
            ->map(fn (Area $a): array => ['id' => $a->id, 'nombre' => $a->nombre])
            ->all();
    }

    /**
     * Borra la foto anterior del disco privado (si existía) y guarda la
     * nueva. Devuelve `null` si el `store()` falla, para no dejar una ruta
     * inválida en la columna.
     */
    private function reemplazarFoto(Colaborador $colaborador, GuardarColaboradorRequest $request): ?string
    {
        if ($colaborador->foto_ruta !== null) {
            Storage::disk('local')->delete($colaborador->foto_ruta);
        }

        return $request->file('foto')->store("colaboradores/{$colaborador->empresa_id}", 'local') ?: null;
    }

    /**
     * Mantiene la columna espejo `area` sincronizada con el nombre del área.
     */
    private function nombreAreaEspejo(int $empresaId, ?int $areaId, mixed $areaTexto): ?string
    {
        if ($areaId !== null) {
            $area = Area::query()->where('empresa_id', $empresaId)->whereKey($areaId)->first();

            if ($area !== null) {
                return $area->nombre;
            }
        }

        return is_string($areaTexto) && trim($areaTexto) !== '' ? trim($areaTexto) : null;
    }
}
