<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Controllers\Concerns\CreaConCodigoUnico;
use App\Http\Controllers\Concerns\ExportaListado;
use App\Http\Controllers\Concerns\ReconciliaSecuenciaCodigo;
use App\Http\Requests\Contratos\GuardarContratoRequest;
use App\Models\Contrato;
use App\Models\Empresa;
use App\Servicios\ServicioAuditoria;
use App\Soporte\ContextoExportacion;
use App\Soporte\ServicioGeneradorCodigos;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Contratos comerciales de una empresa (p. ej. "Laboratorios Clínicos
 * Polab"), de los que se derivan los Servicios operativos. Estructura
 * administrativa: sin stock, sin cascada de desactivación hacia Servicios
 * (cada Servicio conserva su propio estado; usarlo exige además que su
 * Contrato esté activo — ver `GuardarServicioRequest`/`GuardarEntregaRequest`).
 */
class ContratoController extends Controller
{
    use ConEmpresa;
    use CreaConCodigoUnico;
    use ExportaListado;
    use ReconciliaSecuenciaCodigo;

    public function __construct(
        private readonly ServicioAuditoria $auditoria,
        private readonly ServicioGeneradorCodigos $codigos,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Contrato::class);

        $usuario = $request->user();
        $empresaFiltro = $this->empresaDelFiltro($request);
        $filtros = $this->filtrosListado($request);

        $contratos = $this->consultaContratos($request, $filtros)
            ->paginate($this->porPagina())
            ->withQueryString()
            ->through(fn (Contrato $c): array => [
                'id' => $c->id,
                'nombre' => $c->nombre,
                'codigo' => $c->codigo,
                'descripcion' => $c->descripcion,
                'fecha_inicio' => $c->fecha_inicio?->toDateString(),
                'fecha_fin' => $c->fecha_fin?->toDateString(),
                'activo' => $c->activo,
                'empresa' => ['id' => $c->empresa_id, 'nombre_comercial' => $c->empresa?->nombre_comercial],
                'servicios_total' => (int) $c->servicios_count,
                'servicios_activos' => (int) $c->servicios_activos_count,
            ]);

        return Inertia::render('Contratos/Index', [
            'contratos' => $contratos,
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
            'filtros' => [
                'buscar' => $filtros['buscar'] ?? '',
                'estado' => $filtros['estado'] ?? '',
                'orden' => $filtros['orden'] ?? 'az',
                'empresa_id' => $empresaFiltro?->id,
            ],
            'permisos' => [
                'crear' => $usuario->can('create', Contrato::class),
                'editar' => $usuario->can('contratos.editar'),
                'administrar' => $usuario->can('contratos.administrar'),
                'verEliminados' => $usuario->can('contratos.administrar'),
            ],
        ]);
    }

    /**
     * Excel/PDF del listado, respetando los mismos filtros que `index()`.
     */
    public function exportar(Request $request): BinaryFileResponse|HttpResponse
    {
        $this->authorize('viewAny', Contrato::class);

        $filtros = $this->filtrosListado($request);
        $empresaFiltro = $this->empresaDelFiltro($request);
        $contratos = $this->consultaContratos($request, $filtros)->get();

        $filas = $contratos->map(fn (Contrato $c): array => [
            $c->nombre,
            $c->codigo,
            $c->descripcion,
            $c->fecha_inicio?->toDateString(),
            $c->fecha_fin?->toDateString(),
            $c->activo ? 'Activo' : 'Inactivo',
            $c->empresa?->nombre_comercial,
            (int) $c->servicios_count,
            (int) $c->servicios_activos_count,
        ])->all();

        $filtrosHumanos = array_filter([
            'Búsqueda' => $filtros['buscar'] ?? null,
            'Estado' => match ($filtros['estado'] ?? null) {
                'activos' => 'Activos',
                'inactivos' => 'Eliminados',
                default => null,
            },
        ]);

        $contexto = new ContextoExportacion('Contratos', $empresaFiltro, $filtrosHumanos, $contratos->count());

        return $this->respuestaExportacion($request->input('formato', 'xlsx'), $filas, [
            'Nombre', 'Código', 'Descripción', 'Fecha inicio', 'Fecha fin', 'Estado', 'Empresa', 'Servicios (total)', 'Servicios activos',
        ], $contexto);
    }

    /**
     * @return array<string, mixed>
     */
    private function filtrosListado(Request $request): array
    {
        return $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', 'in:activos,inactivos'],
            'orden' => ['nullable', 'in:az,za'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Builder<Contrato>
     */
    private function consultaContratos(Request $request, array $filtros): Builder
    {
        $idsAutorizadas = $this->idsEmpresasAutorizadas($request);
        $empresaFiltro = $this->empresaDelFiltro($request);
        $orden = ($filtros['orden'] ?? 'az') === 'za' ? 'desc' : 'asc';

        // Sólo quien puede administrar contratos puede verlos eliminados en
        // el listado. Para el resto, "activo" se fuerza sin importar qué
        // `estado` pida la URL.
        $puedeVerEliminados = $request->user()->can('contratos.administrar');

        return Contrato::query()
            ->whereIn('empresa_id', $idsAutorizadas)
            ->when($empresaFiltro !== null, fn (Builder $q) => $q->where('empresa_id', $empresaFiltro->id))
            ->with('empresa:id,nombre_comercial')
            ->withCount(['servicios', 'serviciosActivos'])
            ->when($filtros['buscar'] ?? null, function (Builder $q, string $buscar): void {
                $q->where(function (Builder $sub) use ($buscar): void {
                    $sub->where('nombre', 'like', "%{$buscar}%")
                        ->orWhere('codigo', 'like', "%{$buscar}%");
                });
            })
            ->when(! $puedeVerEliminados, fn (Builder $q) => $q->where('activo', true))
            ->when($puedeVerEliminados && ($filtros['estado'] ?? null) === 'activos', fn (Builder $q) => $q->where('activo', true))
            ->when($puedeVerEliminados && ($filtros['estado'] ?? null) === 'inactivos', fn (Builder $q) => $q->where('activo', false))
            ->orderBy('nombre', $orden);
    }

    /**
     * Búsqueda con autocompletado de contratos ACTIVOS de UNA empresa
     * (BuscadorAsync: alta de Servicio, Entregas, Cambiar servicio). Requiere
     * `empresa_id`.
     */
    public function buscar(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Contrato::class);

        $empresa = $this->empresaDelFiltro($request);

        if ($empresa === null) {
            return response()->json(['contratos' => []]);
        }

        $termino = trim((string) $request->query('q', ''));

        $contratos = Contrato::query()
            ->where('empresa_id', $empresa->id)
            ->where('activo', true)
            ->when($termino !== '', fn (Builder $q) => $q->where('nombre', 'like', "%{$termino}%"))
            ->orderBy('nombre')
            ->limit(20)
            ->get(['id', 'nombre', 'codigo']);

        return response()->json(['contratos' => $contratos]);
    }

    public function show(Request $request, Contrato $contrato): Response
    {
        $this->authorize('view', $contrato);

        $contrato->loadCount(['servicios', 'serviciosActivos']);
        $contrato->load('empresa:id,nombre_comercial');

        return Inertia::render('Contratos/Detalle', [
            'contrato' => [
                ...$contrato->only(['id', 'nombre', 'codigo', 'descripcion', 'fecha_inicio', 'fecha_fin', 'activo']),
                'fecha_inicio' => $contrato->fecha_inicio?->toDateString(),
                'fecha_fin' => $contrato->fecha_fin?->toDateString(),
                'empresa' => [
                    'id' => $contrato->empresa->id,
                    'nombre_comercial' => $contrato->empresa->nombre_comercial,
                ],
                'servicios_total' => (int) $contrato->servicios_count,
                'servicios_activos' => (int) $contrato->servicios_activos_count,
            ],
            'permisos' => [
                'editar' => $request->user()->can('update', $contrato),
                'administrar' => $request->user()->can('administrar', $contrato),
            ],
        ]);
    }

    public function store(GuardarContratoRequest $request): RedirectResponse
    {
        $empresa = $request->empresaResuelta();
        $datos = $request->validated();

        $contrato = $this->crearConCodigoUnico(fn () => Contrato::query()->create([
            ...$datos,
            'empresa_id' => $empresa->id,
            'codigo' => $this->generarCodigo($empresa),
            'activo' => true,
        ]));

        $this->auditoria->registrar('contratos', 'crear', [
            'tipo_entidad' => Contrato::class, 'entidad_id' => $contrato->id, 'empresa_id' => $empresa->id,
            'descripcion' => 'Alta de contrato '.$contrato->nombre,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Contrato registrado correctamente.']);
    }

    public function update(GuardarContratoRequest $request, Contrato $contrato): RedirectResponse
    {
        $contrato->update($request->validated());

        $this->auditoria->registrar('contratos', 'editar', [
            'tipo_entidad' => Contrato::class, 'entidad_id' => $contrato->id, 'empresa_id' => $contrato->empresa_id,
            'descripcion' => 'Edición de contrato '.$contrato->nombre,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Contrato actualizado correctamente.']);
    }

    public function toggle(Contrato $contrato): RedirectResponse
    {
        $this->authorize('administrar', $contrato);

        $contrato->update(['activo' => ! $contrato->activo]);

        $this->auditoria->registrar('contratos', $contrato->activo ? 'activar' : 'desactivar', [
            'tipo_entidad' => Contrato::class, 'entidad_id' => $contrato->id, 'empresa_id' => $contrato->empresa_id,
            'descripcion' => ($contrato->activo ? 'Activación' : 'Desactivación').' de contrato '.$contrato->nombre,
            'valores_anteriores' => ['activo' => ! $contrato->activo],
            'valores_nuevos' => ['activo' => $contrato->activo],
        ]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $contrato->activo ? 'Contrato restaurado correctamente.' : 'Contrato eliminado correctamente. Sus servicios y datos históricos siguen visibles.',
        ]);
    }

    /**
     * Genera un código consecutivo y único dentro de la empresa (CON-0001,
     * CON-0002, …). Race-safe: `ServicioGeneradorCodigos` bloquea el contador
     * dentro de una transacción y reconcilia contra el mayor código
     * "CON-XXXX" REALMENTE existente en esa empresa en cada llamada.
     */
    private function generarCodigo(Empresa $empresa): string
    {
        return $this->codigos->siguienteConPrefijo($empresa, 'contrato', 'CON', semilla: fn (): int => $this->maximoSufijo(
            Contrato::query()->where('empresa_id', $empresa->id)->where('codigo', 'like', 'CON-%')->pluck('codigo'),
            'CON-',
        ));
    }

    /**
     * Previsualización NO autoritativa del siguiente código de contrato para
     * la empresa indicada — no reserva el consecutivo.
     */
    public function siguienteCodigo(Request $request): JsonResponse
    {
        $this->authorize('create', Contrato::class);

        $empresa = $this->resolverEmpresa($request);

        $codigo = $this->codigos->siguienteConPrefijoAproximado($empresa, 'contrato', 'CON', semilla: fn (): int => $this->maximoSufijo(
            Contrato::query()->where('empresa_id', $empresa->id)->where('codigo', 'like', 'CON-%')->pluck('codigo'),
            'CON-',
        ));

        return response()->json(['codigo' => $codigo]);
    }
}
