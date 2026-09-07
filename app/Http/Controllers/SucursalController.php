<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Controllers\Concerns\CreaConCodigoUnico;
use App\Http\Controllers\Concerns\ExportaListado;
use App\Http\Controllers\Concerns\ReactivaSuspendidos;
use App\Http\Controllers\Concerns\ReconciliaSecuenciaCodigo;
use App\Http\Requests\Sucursales\GuardarSucursalRequest;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioCascadaSuspension;
use App\Soporte\ContextoExportacion;
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
    use CreaConCodigoUnico;
    use ExportaListado;
    use ReactivaSuspendidos;
    use ReconciliaSecuenciaCodigo;

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
                'verEliminadas' => $usuario->can('sucursales.desactivar'),
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
        $empresaFiltro = $this->empresaDelFiltro($request);
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

        $filtrosHumanos = array_filter([
            'Búsqueda' => $filtros['buscar'] ?? null,
            'Estado' => match ($filtros['estado'] ?? null) {
                'activas' => 'Activas',
                'inactivas' => 'Eliminadas',
                default => null,
            },
        ]);

        $contexto = new ContextoExportacion('Sucursales', $empresaFiltro, $filtrosHumanos, $sucursales->count());

        return $this->respuestaExportacion($request->input('formato', 'xlsx'), $filas, [
            'Código', 'Nombre', 'Dirección', 'Teléfono', 'Estado', 'Empresa', 'Colaboradores activos',
        ], $contexto);
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

        // Sólo quien puede eliminar/restaurar sucursales puede verlas
        // eliminadas en el listado. Para el resto, "activa" se fuerza sin
        // importar qué `estado` pida la URL (no basta con ocultar el botón).
        $puedeVerEliminadas = $usuario->can('sucursales.desactivar');

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
            ->when(! $puedeVerEliminadas, fn (Builder $q) => $q->where('activa', true))
            ->when($puedeVerEliminadas && ($filtros['estado'] ?? null) === 'activas', fn (Builder $q) => $q->where('activa', true))
            ->when($puedeVerEliminadas && ($filtros['estado'] ?? null) === 'inactivas', fn (Builder $q) => $q->where('activa', false))
            ->orderBy('nombre', $orden);
    }

    /**
     * Búsqueda con autocompletado de sucursales (BuscadorAsync). Con
     * `empresa_id`, acota a esa empresa (p. ej. alta de colaborador). Sin
     * `empresa_id`, busca en TODAS las empresas autorizadas del usuario (p.
     * ej. el Dashboard en modo "todas las empresas": ningún filtro debe
     * dejar el buscador inutilizable sólo porque no hay una empresa elegida).
     * En ambos casos respeta el alcance real del usuario.
     */
    public function buscar(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Sucursal::class);

        if ($request->filled('empresa_id')) {
            $empresa = $this->empresaDelFiltro($request);
            $sucursalesAutorizadas = $empresa === null
                ? collect()
                : $this->acceso()->sucursalesAutorizadas($request->user(), $empresa);
        } else {
            $sucursalesAutorizadas = $this->acceso()->sucursalesAutorizadasGlobal($request->user());
        }

        $termino = Str::lower(trim((string) $request->query('q', '')));

        $sucursales = $sucursalesAutorizadas
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

        $sucursal = $this->crearConCodigoUnico(fn () => Sucursal::query()->create([
            ...$datos,
            'empresa_id' => $empresa->id,
            'codigo' => $this->generarCodigo($empresa),
            'activa' => true,
        ]));

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

        $mensaje = $sucursal->activa ? 'Sucursal restaurada correctamente.' : 'Sucursal eliminada correctamente.';

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
            'valores_anteriores' => ['activa' => ! $sucursal->activa],
            'valores_nuevos' => ['activa' => $sucursal->activa],
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => $mensaje]);
    }

    /**
     * Genera un código interno consecutivo y único dentro de la empresa
     * (SUC-0001, SUC-0002, …). Race-safe: `ServicioGeneradorCodigos` bloquea
     * el contador dentro de una transacción (nunca `count() + 1` sin lock) y
     * reconcilia contra el mayor código "SUC-XXXX" REALMENTE existente en
     * esa empresa en cada llamada — nunca repite un código ya usado aunque
     * el contador haya quedado atrasado.
     */
    private function generarCodigo(Empresa $empresa): string
    {
        return $this->codigos->siguienteConPrefijo($empresa, 'sucursal', 'SUC', semilla: fn (): int => $this->maximoSufijo(
            Sucursal::query()->where('empresa_id', $empresa->id)->where('codigo', 'like', 'SUC-%')->pluck('codigo'),
            'SUC-',
        ));
    }

    /**
     * Previsualización NO autoritativa del siguiente código de sucursal para
     * la empresa indicada — no reserva el consecutivo. El valor definitivo
     * se calcula de nuevo, atómicamente, en `store()`.
     */
    public function siguienteCodigo(Request $request): JsonResponse
    {
        $this->authorize('create', Sucursal::class);

        $empresa = $this->resolverEmpresa($request);

        $codigo = $this->codigos->siguienteConPrefijoAproximado($empresa, 'sucursal', 'SUC', semilla: fn (): int => $this->maximoSufijo(
            Sucursal::query()->where('empresa_id', $empresa->id)->where('codigo', 'like', 'SUC-%')->pluck('codigo'),
            'SUC-',
        ));

        return response()->json(['codigo' => $codigo]);
    }
}
