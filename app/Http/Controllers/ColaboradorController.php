<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Requests\Colaboradores\GuardarColaboradorRequest;
use App\Models\Area;
use App\Models\Colaborador;
use App\Models\Sucursal;
use App\Servicios\ServicioAuditoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Colaboradores por empresa. La empresa llega como filtro (listado) o campo
 * `empresa_id` (alta); en edición queda fijada por el registro. Sucursales y
 * áreas del formulario se acotan a la empresa elegida y al alcance del usuario.
 */
class ColaboradorController extends Controller
{
    use ConEmpresa;

    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Colaborador::class);

        $usuario = $request->user();
        $idsAutorizadas = $this->idsEmpresasAutorizadas($request);
        $empresaFiltro = $this->empresaDelFiltro($request);

        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'sucursal_id' => ['nullable', 'integer'],
            'area_id' => ['nullable', 'integer'],
            'estado' => ['nullable', 'in:activos,inactivos,todos'],
        ]);

        $sucursalesVisibles = $idsAutorizadas
            ->flatMap(fn (int $id): array => $this->acceso()->sucursalesAutorizadas($usuario, $id)->pluck('id')->all())
            ->unique()->values();

        $colaboradores = Colaborador::query()
            ->whereIn('empresa_id', $idsAutorizadas)
            ->whereIn('sucursal_id', $sucursalesVisibles)
            ->when($empresaFiltro !== null, fn ($q) => $q->where('empresa_id', $empresaFiltro->id))
            ->when($filtros['buscar'] ?? null, fn ($q, $b) => $q->where(fn ($s) => $s
                ->where('nombre_completo', 'like', "%{$b}%")
                ->orWhere('numero_empleado', 'like', "%{$b}%")))
            ->when($filtros['sucursal_id'] ?? null, fn ($q, $s) => $q->where('sucursal_id', $s))
            ->when($filtros['area_id'] ?? null, fn ($q, $a) => $q->where('area_id', $a))
            ->when(($filtros['estado'] ?? 'activos') === 'activos', fn ($q) => $q->where('activo', true))
            ->when(($filtros['estado'] ?? null) === 'inactivos', fn ($q) => $q->where('activo', false))
            ->with(['sucursal:id,nombre', 'empresa:id,nombre_comercial'])
            ->orderBy('nombre_completo')
            ->paginate($this->porPagina())
            ->withQueryString();

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
        ]);
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
                $preseleccion = ['sucursal_id' => $sucursal->id, 'empresa_id' => $sucursal->empresa_id];
            }
        }

        return Inertia::render('Colaboradores/Formulario', [
            'colaborador' => null,
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
            'catalogosPorEmpresa' => $this->catalogosPorEmpresa($request),
            'sucursalPreseleccionadaId' => $preseleccion['sucursal_id'] ?? null,
            'empresaPreseleccionadaId' => $preseleccion['empresa_id'] ?? null,
        ]);
    }

    public function store(GuardarColaboradorRequest $request): RedirectResponse
    {
        $empresa = $request->empresaResuelta();

        $colaborador = Colaborador::query()->create([
            ...$request->safe()->except(['activo', 'empresa_id']),
            'empresa_id' => $empresa->id,
            'area' => $this->nombreAreaEspejo($empresa->id, $request->integer('area_id') ?: null, $request->input('area')),
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

        return Inertia::render('Colaboradores/Formulario', [
            'colaborador' => $colaborador->only(['id', 'empresa_id', 'numero_empleado', 'nombre_completo', 'sucursal_id', 'puesto', 'area', 'area_id', 'correo', 'activo']),
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
            'catalogosPorEmpresa' => $this->catalogosPorEmpresa($request, [$colaborador->empresa_id]),
        ]);
    }

    public function update(GuardarColaboradorRequest $request, Colaborador $colaborador): RedirectResponse
    {
        $anteriores = $colaborador->toArray();

        $colaborador->update([
            ...$request->safe()->except(['activo', 'empresa_id']),
            'area' => $this->nombreAreaEspejo($colaborador->empresa_id, $request->integer('area_id') ?: null, $request->input('area')),
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
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => $colaborador->activo ? 'Colaborador activado.' : 'Colaborador desactivado.']);
    }

    /**
     * Sucursales (según alcance) y áreas de cada empresa autorizada, para el
     * formulario dependiente de la empresa elegida.
     *
     * @param  array<int, int>  $soloEmpresas
     * @return array<int, array{sucursales: mixed, areas: array<int, mixed>}>
     */
    private function catalogosPorEmpresa(Request $request, array $soloEmpresas = []): array
    {
        $usuario = $request->user();

        return $this->empresasAutorizadas($request)
            ->when($soloEmpresas !== [], fn ($c) => $c->whereIn('id', $soloEmpresas))
            ->mapWithKeys(fn ($e): array => [$e->id => [
                'sucursales' => $this->acceso()->sucursalesAutorizadas($usuario, $e)->map->only(['id', 'nombre'])->values(),
                'areas' => $this->areasDe($e->id),
            ]])->all();
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
