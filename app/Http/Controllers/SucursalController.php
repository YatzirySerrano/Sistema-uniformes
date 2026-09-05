<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Controllers\Concerns\ExportaListado;
use App\Http\Controllers\Concerns\ReactivaSuspendidos;
use App\Http\Requests\Sucursales\GuardarSucursalRequest;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioCascadaSuspension;
use App\Soporte\ServicioGeneradorCodigos;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class SucursalController extends Controller
{
    use ConEmpresa;
    use ExportaListado;
    use ReactivaSuspendidos;

    public function __construct(
        private readonly ServicioAuditoria $auditoria,
        private readonly ServicioCascadaSuspension $cascada,
        private readonly ServicioGeneradorCodigos $codigos,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Sucursal::class);

        $usuario = $request->user();
        $empresaFiltro = $this->empresaDelFiltro($request);
        $filtros = $this->filtrosListado($request);

        $sucursales = $this->consultaSucursales($request, $filtros)
            ->paginate($this->porPagina())
            ->withQueryString()
            ->through(fn (Sucursal $s): array => [
                'id' => $s->id,
                'codigo' => $s->codigo,
                'nombre' => $s->nombre,
                'direccion' => $s->direccion,
                'telefono' => $s->telefono,
                'activa' => $s->activa,
                'empresa' => ['id' => $s->empresa_id, 'nombre_comercial' => $s->empresa?->nombre_comercial],
                'colaboradores_activos' => (int) $s->colaboradores_activos_count,
            ]);

        return Inertia::render('Sucursales/Index', [
            'sucursales' => $sucursales,
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
            'filtros' => [
                'buscar' => $filtros['buscar'] ?? '',
                'estado' => $filtros['estado'] ?? '',
                'orden' => $filtros['orden'] ?? 'az',
                'empresa_id' => $empresaFiltro?->id,
            ],
            'permisos' => [
                'crear' => $usuario->can('create', Sucursal::class),
                'editar' => $usuario->can('sucursales.editar'),
                'desactivar' => $usuario->can('sucursales.desactivar'),
            ],
        ]);
    }

    /**
     * Excel/PDF del listado, respetando los mismos filtros que `index()`.
     */
    public function exportar(Request $request): BinaryFileResponse|HttpResponse
    {
        $this->authorize('viewAny', Sucursal::class);

        $filtros = $this->filtrosListado($request);
        $sucursales = $this->consultaSucursales($request, $filtros)->get();

        $filas = $sucursales->map(fn (Sucursal $s): array => [
            $s->codigo,
            $s->nombre,
            $s->direccion,
            $s->telefono,
            $s->activa ? 'Activa' : 'Inactiva',
            $s->empresa?->nombre_comercial,
            (int) $s->colaboradores_activos_count,
        ])->all();

        return $this->respuestaExportacion($request->input('formato', 'xlsx'), $filas, [
            'Código', 'Nombre', 'Dirección', 'Teléfono', 'Estado', 'Empresa', 'Colaboradores activos',
        ], 'Sucursales');
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
     * @return Builder<Sucursal>
     */
    private function consultaSucursales(Request $request, array $filtros): Builder
    {
        $usuario = $request->user();
        $idsAutorizadas = $this->idsEmpresasAutorizadas($request);
        $empresaFiltro = $this->empresaDelFiltro($request);
        $orden = ($filtros['orden'] ?? 'az') === 'za' ? 'desc' : 'asc';

        // Alcance de sucursales visibles: por empresa autorizada, sólo las del
        // alcance del usuario (todas si es global o no tiene asignación específica).
        $sucursalesVisibles = $idsAutorizadas
            ->flatMap(fn (int $empresaId): array => $this->acceso()->sucursalesAutorizadas($usuario, $empresaId)->pluck('id')->all())
            ->unique()
            ->values();

        return Sucursal::query()
            ->whereIn('id', $sucursalesVisibles)
            ->when($empresaFiltro !== null, fn (Builder $q) => $q->where('empresa_id', $empresaFiltro->id))
            ->with('empresa:id,nombre_comercial')
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
            ->orderBy('nombre', $orden);
    }

    /**
     * Búsqueda con autocompletado de sucursales de UNA empresa (BuscadorAsync,
     * p. ej. alta de colaborador). Requiere `empresa_id` y respeta el alcance
     * del usuario dentro de esa empresa.
     */
    public function buscar(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Sucursal::class);

        $empresa = $this->empresaDelFiltro($request);

        if ($empresa === null) {
            return response()->json(['sucursales' => []]);
        }

        $termino = Str::lower(trim((string) $request->query('q', '')));

        $sucursales = $this->acceso()->sucursalesAutorizadas($request->user(), $empresa)
            // Sólo sucursales ACTIVAS: una sucursal desactivada no es un
            // destino/contexto operativo válido para colaboradores o entregas.
            ->filter(fn (Sucursal $s): bool => $s->activa)
            ->when($termino !== '', fn ($c) => $c->filter(fn (Sucursal $s): bool => str_contains(Str::lower($s->nombre), $termino)))
            ->take(20)
            ->map(fn (Sucursal $s): array => ['id' => $s->id, 'nombre' => $s->nombre])
            ->values();

        return response()->json(['sucursales' => $sucursales]);
    }

    public function show(Request $request, Sucursal $sucursal): Response
    {
        $this->authorize('view', $sucursal);

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
            'suspendidos' => $this->cascada->paraVista($this->cascada->checklistDe($sucursal)),
        ]);
    }

    /**
     * Reactivación selectiva (Fase 7): sólo levanta las suspensiones VIGENTES
     * causadas por ESTA sucursal cuyo id venga marcado, y sólo si el
     * colaborador ya no tiene otra dependencia obligatoria inactiva (p. ej.
     * la propia empresa) — blindaje multicausa.
     */
    public function reactivarSuspendidos(Request $request, Sucursal $sucursal): RedirectResponse
    {
        $this->authorize('desactivar', $sucursal);

        $ids = array_map('intval', $request->input('ids', []));
        $resultado = $this->cascada->reactivarSeleccionados($sucursal, $ids, $request->user()?->id);

        return back()->with('toast', $this->toastDeReactivacion($resultado));
    }

    public function store(GuardarSucursalRequest $request): RedirectResponse
    {
        $empresa = $request->empresaResuelta();

        $datos = $request->validated();
        $datos['codigo'] = ($datos['codigo'] ?? null) ?: $this->generarCodigo($empresa);

        $sucursal = Sucursal::query()->create([
            ...$datos,
            'empresa_id' => $empresa->id,
            'activa' => true,
        ]);

        $this->auditoria->registrar('sucursales', 'crear', [
            'tipo_entidad' => Sucursal::class, 'entidad_id' => $sucursal->id,
            'empresa_id' => $empresa->id, 'sucursal_id' => $sucursal->id,
            'descripcion' => 'Alta de sucursal '.$sucursal->nombre,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Sucursal registrada correctamente.']);
    }

    public function update(GuardarSucursalRequest $request, Sucursal $sucursal): RedirectResponse
    {
        $sucursal->update($request->validated());

        $this->auditoria->registrar('sucursales', 'editar', [
            'tipo_entidad' => Sucursal::class, 'entidad_id' => $sucursal->id,
            'empresa_id' => $sucursal->empresa_id, 'sucursal_id' => $sucursal->id,
            'descripcion' => 'Edición de sucursal '.$sucursal->nombre,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Sucursal actualizada correctamente.']);
    }

    public function toggle(Request $request, Sucursal $sucursal): RedirectResponse
    {
        $this->authorize('desactivar', $sucursal);

        $sucursal->update(['activa' => ! $sucursal->activa]);

        $mensaje = $sucursal->activa ? 'Sucursal activada correctamente.' : 'Sucursal desactivada correctamente.';

        if (! $sucursal->activa) {
            // Cascada NO destructiva: los colaboradores de esta sucursal
            // quedan suspendidos (nunca los que ya estaban inactivos por otra
            // causa). Reactivación selectiva desde `show()`.
            $suspendidos = $this->cascada->suspender(
                $sucursal,
                Colaborador::query()->where('sucursal_id', $sucursal->id),
                'activo',
                $request->user()?->id,
            );

            if ($suspendidos > 0) {
                $mensaje .= " {$suspendidos} colaborador(es) quedaron suspendidos por cascada.";
            }
        }

        $this->auditoria->registrar('sucursales', $sucursal->activa ? 'activar' : 'desactivar', [
            'tipo_entidad' => Sucursal::class, 'entidad_id' => $sucursal->id,
            'empresa_id' => $sucursal->empresa_id, 'sucursal_id' => $sucursal->id,
            'descripcion' => ($sucursal->activa ? 'Activación' : 'Desactivación').' de sucursal '.$sucursal->nombre,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => $mensaje]);
    }

    /**
     * Genera un código interno consecutivo y único dentro de la empresa
     * (SUC-0001, SUC-0002, …) cuando el usuario no captura uno. Race-safe:
     * `ServicioGeneradorCodigos` bloquea el contador dentro de una
     * transacción (nunca `count() + 1` sin lock).
     */
    private function generarCodigo(Empresa $empresa): string
    {
        return $this->codigos->siguienteConPrefijo($empresa, 'sucursal', 'SUC');
    }
}
