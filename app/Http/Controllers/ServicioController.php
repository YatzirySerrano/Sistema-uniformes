<?php

namespace App\Http\Controllers;

use App\Acciones\AsignarColaboradoresServicio;
use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Controllers\Concerns\CreaConCodigoUnico;
use App\Http\Controllers\Concerns\ExportaListado;
use App\Http\Controllers\Concerns\ReconciliaSecuenciaCodigo;
use App\Http\Requests\Servicios\AsignarColaboradoresServicioRequest;
use App\Http\Requests\Servicios\GuardarServicioRequest;
use App\Models\Colaborador;
use App\Models\Contrato;
use App\Models\Servicio;
use App\Servicios\ServicioAuditoria;
use App\Soporte\ContextoExportacion;
use App\Soporte\ServicioGeneradorCodigosGlobal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Servicios operativos (puestos de vigilancia) derivados de un Contrato y
 * anclados administrativamente a una Sucursal. `Servicio` NO tiene
 * `empresa_id` propio — por eso, a diferencia del resto de módulos, NO tiene
 * `empresa_id` como código autogenerado por-empresa: usa
 * `ServicioGeneradorCodigosGlobal` (mismo motivo que `Almacen`, que tampoco
 * pertenece a una sola empresa) y su unicidad es de PLATAFORMA.
 */
class ServicioController extends Controller
{
    use ConEmpresa;
    use CreaConCodigoUnico;
    use ExportaListado;
    use ReconciliaSecuenciaCodigo;

    public function __construct(
        private readonly ServicioAuditoria $auditoria,
        private readonly ServicioGeneradorCodigosGlobal $codigos,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Servicio::class);

        $usuario = $request->user();
        $empresaFiltro = $this->empresaDelFiltro($request);
        $filtros = $this->filtrosListado($request);

        $servicios = $this->consultaServicios($request, $filtros)
            ->paginate($this->porPagina())
            ->withQueryString()
            ->through(fn (Servicio $s): array => [
                'id' => $s->id,
                'nombre' => $s->nombre,
                'codigo' => $s->codigo,
                'direccion' => $s->direccion,
                'descripcion' => $s->descripcion,
                'activo' => $s->activo,
                'contrato' => ['id' => $s->contrato->id, 'nombre' => $s->contrato->nombre, 'activo' => $s->contrato->activo],
                'sucursal' => ['id' => $s->sucursal->id, 'nombre' => $s->sucursal->nombre],
                'empresa' => ['id' => $s->contrato->empresa_id, 'nombre_comercial' => $s->contrato->empresa?->nombre_comercial],
            ]);

        return Inertia::render('Servicios/Index', [
            'servicios' => $servicios,
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
            'filtros' => [
                'buscar' => $filtros['buscar'] ?? '',
                'estado' => $filtros['estado'] ?? '',
                'orden' => $filtros['orden'] ?? 'az',
                'empresa_id' => $empresaFiltro?->id,
                'contrato_id' => $filtros['contrato_id'] ?? null,
            ],
            'permisos' => [
                'crear' => $usuario->can('create', Servicio::class),
                'editar' => $usuario->can('servicios.editar'),
                'administrar' => $usuario->can('servicios.administrar'),
                'verEliminados' => $usuario->can('servicios.administrar'),
            ],
        ]);
    }

    /**
     * Excel/PDF del listado, respetando los mismos filtros que `index()`.
     */
    public function exportar(Request $request): BinaryFileResponse|HttpResponse
    {
        $this->authorize('viewAny', Servicio::class);

        $filtros = $this->filtrosListado($request);
        $empresaFiltro = $this->empresaDelFiltro($request);
        $servicios = $this->consultaServicios($request, $filtros)->get();

        $filas = $servicios->map(fn (Servicio $s): array => [
            $s->nombre,
            $s->codigo,
            $s->contrato->nombre,
            $s->sucursal->nombre,
            $s->direccion,
            $s->activo ? 'Activo' : 'Inactivo',
            $s->contrato->empresa?->nombre_comercial,
        ])->all();

        $filtrosHumanos = array_filter([
            'Búsqueda' => $filtros['buscar'] ?? null,
            'Contrato' => ($filtros['contrato_id'] ?? null) ? Contrato::query()->find((int) $filtros['contrato_id'])?->nombre : null,
            'Estado' => match ($filtros['estado'] ?? null) {
                'activos' => 'Activos',
                'inactivos' => 'Eliminados',
                default => null,
            },
        ]);

        $contexto = new ContextoExportacion('Servicios', $empresaFiltro, $filtrosHumanos, $servicios->count());

        return $this->respuestaExportacion($request->input('formato', 'xlsx'), $filas, [
            'Nombre', 'Código', 'Contrato', 'Sucursal', 'Dirección', 'Estado', 'Empresa',
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
            'contrato_id' => ['nullable', 'integer'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Builder<Servicio>
     */
    private function consultaServicios(Request $request, array $filtros): Builder
    {
        $idsAutorizadas = $this->idsEmpresasAutorizadas($request);
        $empresaFiltro = $this->empresaDelFiltro($request);
        $orden = ($filtros['orden'] ?? 'az') === 'za' ? 'desc' : 'asc';

        // Sólo quien puede administrar servicios puede verlos eliminados en
        // el listado. Para el resto, "activo" se fuerza sin importar qué
        // `estado` pida la URL.
        $puedeVerEliminados = $request->user()->can('servicios.administrar');

        return Servicio::query()
            ->whereHas('contrato', fn (Builder $q) => $q->whereIn('empresa_id', $idsAutorizadas))
            ->when($empresaFiltro !== null, fn (Builder $q) => $q->whereHas('contrato', fn (Builder $c) => $c->where('empresa_id', $empresaFiltro->id)))
            ->when($filtros['contrato_id'] ?? null, fn (Builder $q, $c) => $q->where('contrato_id', $c))
            ->with(['contrato:id,nombre,activo,empresa_id', 'contrato.empresa:id,nombre_comercial', 'sucursal:id,nombre'])
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
     * Búsqueda con autocompletado de servicios ACTIVOS (de contrato también
     * activo) para BuscadorAsync. Acepta `contrato_id` (uso principal:
     * entrega / cambiar servicio, acota a un contrato concreto) o
     * `empresa_id` (filtros de listado, entre todos los contratos de esa
     * empresa). Sin ninguno de los dos, devuelve vacío.
     */
    public function buscar(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Servicio::class);

        $termino = trim((string) $request->query('q', ''));

        $consulta = Servicio::query()
            ->where('activo', true)
            ->whereHas('contrato', fn (Builder $q) => $q->where('activo', true));

        if ($request->filled('contrato_id')) {
            $contrato = Contrato::query()->find((int) $request->query('contrato_id'));

            if ($contrato === null || ! $request->user()->puedeAccederEmpresa($contrato->empresa_id)) {
                return response()->json(['servicios' => []]);
            }

            $consulta->where('contrato_id', $contrato->id);
        } elseif ($request->filled('empresa_id')) {
            $empresa = $this->empresaDelFiltro($request);

            if ($empresa === null) {
                return response()->json(['servicios' => []]);
            }

            $consulta->whereHas('contrato', fn (Builder $q) => $q->where('empresa_id', $empresa->id));
        } else {
            return response()->json(['servicios' => []]);
        }

        $servicios = $consulta
            ->when($termino !== '', fn (Builder $q) => $q->where('nombre', 'like', "%{$termino}%"))
            ->with('contrato:id,nombre')
            ->orderBy('nombre')
            ->limit(20)
            ->get(['id', 'nombre', 'codigo', 'contrato_id'])
            ->map(fn (Servicio $s): array => [
                'id' => $s->id,
                'nombre' => $s->nombre,
                'codigo' => $s->codigo,
                'contrato_id' => $s->contrato_id,
                'contrato' => $s->contrato?->nombre,
            ]);

        return response()->json(['servicios' => $servicios]);
    }

    public function show(Request $request, Servicio $servicio): Response
    {
        $this->authorize('view', $servicio);

        $servicio->load(['contrato:id,nombre,codigo,activo,empresa_id', 'contrato.empresa:id,nombre_comercial', 'sucursal:id,nombre']);
        $servicio->loadCount('colaboradoresActuales');

        // Sub-listado paginado en servidor (pensado para 3 000+ colaboradores):
        // nunca se cargan todos de golpe. `colab_page` es un `pageName` propio
        // para no chocar con ninguna otra paginación de la página.
        $filtrosColaboradores = $request->validate([
            'colab_buscar' => ['nullable', 'string', 'max:100'],
        ]);

        return Inertia::render('Servicios/Detalle', [
            'servicio' => [
                ...$servicio->only(['id', 'nombre', 'codigo', 'direccion', 'descripcion', 'activo']),
                'contrato' => [
                    'id' => $servicio->contrato->id,
                    'nombre' => $servicio->contrato->nombre,
                    'activo' => $servicio->contrato->activo,
                ],
                'sucursal' => ['id' => $servicio->sucursal->id, 'nombre' => $servicio->sucursal->nombre],
                'empresa' => [
                    'id' => $servicio->contrato->empresa_id,
                    'nombre_comercial' => $servicio->contrato->empresa?->nombre_comercial,
                ],
                'colaboradores_actuales' => (int) $servicio->colaboradores_actuales_count,
            ],
            'colaboradoresAsignados' => $this->consultaColaboradoresAsignados($servicio, $filtrosColaboradores)
                ->paginate(10, ['*'], 'colab_page')
                ->withQueryString()
                ->through(fn (Colaborador $c): array => [
                    'id' => $c->id,
                    'nombre_completo' => $c->nombre_completo,
                    'numero_empleado' => $c->numero_empleado,
                    'sucursal' => $c->sucursal?->nombre,
                    'activo' => $c->activo,
                ]),
            'filtrosColaboradores' => ['buscar' => $filtrosColaboradores['colab_buscar'] ?? ''],
            'permisos' => [
                'editar' => $request->user()->can('update', $servicio),
                'administrar' => $request->user()->can('administrar', $servicio),
                // La administración de personal desde el Servicio usa el MISMO
                // permiso que "Cambiar servicio" del colaborador.
                'asignarColaboradores' => $request->user()->can('colaboradores.editar'),
            ],
        ]);
    }

    /**
     * Colaboradores cuya ubicación operativa VIGENTE
     * (`colaboradores.servicio_actual_id`) es este servicio. Nunca se infiere
     * desde entregas ni desde otra fuente.
     *
     * @param  array<string, mixed>  $filtros
     * @return Builder<Colaborador>
     */
    private function consultaColaboradoresAsignados(Servicio $servicio, array $filtros): Builder
    {
        return Colaborador::query()
            ->where('servicio_actual_id', $servicio->id)
            // Defensa en capas: un colaborador siempre pertenece a la empresa
            // del servicio que tenga asignado (lo garantiza CambiarServicio),
            // pero nunca se muestra personal de otra empresa aunque hubiera una
            // inconsistencia de datos.
            ->where('empresa_id', $servicio->contrato->empresa_id)
            ->when($filtros['colab_buscar'] ?? null, fn (Builder $q, string $b) => $q->where(fn (Builder $s) => $s
                ->where('nombre_completo', 'like', "%{$b}%")
                ->orWhere('numero_empleado', 'like', "%{$b}%")))
            ->with('sucursal:id,nombre')
            ->orderBy('nombre_completo');
    }

    /**
     * Asignación en lote de colaboradores a este servicio (UNA petición, UNA
     * transacción). Reutiliza `CambiarServicioColaborador` por colaborador
     * mediante `AsignarColaboradoresServicio` — misma fuente de verdad y misma
     * auditoría individual que "Colaborador → Cambiar servicio". Quitar un
     * colaborador del servicio NO pasa por aquí: es "Cambiar servicio" con
     * `servicio_id = null`.
     */
    public function asignarColaboradores(AsignarColaboradoresServicioRequest $request, Servicio $servicio, AsignarColaboradoresServicio $accion): RedirectResponse
    {
        $datos = $request->validated();

        $resumen = $accion->ejecutar($servicio, $datos['colaborador_ids'], $datos['motivo'] ?? null);

        $mensaje = $resumen['total'] === 1
            ? 'Se asignó 1 colaborador a este servicio.'
            : "Se asignaron {$resumen['total']} colaboradores a este servicio.";

        if ($resumen['movidos'] > 0) {
            $mensaje .= $resumen['movidos'] === 1
                ? ' 1 provenía de otro servicio y fue cambiado.'
                : " {$resumen['movidos']} provenían de otro servicio y fueron cambiados.";
        }

        if ($resumen['sin_cambio'] > 0) {
            $mensaje .= $resumen['sin_cambio'] === 1
                ? ' 1 ya estaba en este servicio.'
                : " {$resumen['sin_cambio']} ya estaban en este servicio.";
        }

        return back()->with('toast', ['type' => 'success', 'message' => $mensaje]);
    }

    public function store(GuardarServicioRequest $request): RedirectResponse
    {
        $contrato = $request->contratoResuelto();
        $datos = $request->validated();

        $servicio = $this->crearConCodigoUnico(fn () => Servicio::query()->create([
            ...$datos,
            'codigo' => $this->generarCodigo(),
            'activo' => true,
        ]));

        $this->auditoria->registrar('servicios', 'crear', [
            'tipo_entidad' => Servicio::class, 'entidad_id' => $servicio->id, 'empresa_id' => $contrato->empresa_id,
            'descripcion' => 'Alta de servicio '.$servicio->nombre.' (contrato '.$contrato->nombre.')',
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Servicio registrado correctamente.']);
    }

    public function update(GuardarServicioRequest $request, Servicio $servicio): RedirectResponse
    {
        $servicio->update($request->validated());

        $this->auditoria->registrar('servicios', 'editar', [
            'tipo_entidad' => Servicio::class, 'entidad_id' => $servicio->id, 'empresa_id' => $servicio->empresaId(),
            'descripcion' => 'Edición de servicio '.$servicio->nombre,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Servicio actualizado correctamente.']);
    }

    public function toggle(Servicio $servicio): RedirectResponse
    {
        $this->authorize('administrar', $servicio);

        $servicio->update(['activo' => ! $servicio->activo]);

        $this->auditoria->registrar('servicios', $servicio->activo ? 'activar' : 'desactivar', [
            'tipo_entidad' => Servicio::class, 'entidad_id' => $servicio->id, 'empresa_id' => $servicio->empresaId(),
            'descripcion' => ($servicio->activo ? 'Activación' : 'Desactivación').' de servicio '.$servicio->nombre,
            'valores_anteriores' => ['activo' => ! $servicio->activo],
            'valores_nuevos' => ['activo' => $servicio->activo],
        ]);

        return back()->with('toast', [
            'type' => 'success',
            // Nunca se tocan los colaboradores que tengan este servicio como
            // vigente: si el usuario lo desactivó a propósito, la advertencia
            // vive en la vista de detalle (colaboradores_actuales), no aquí.
            'message' => $servicio->activo ? 'Servicio restaurado correctamente.' : 'Servicio eliminado correctamente. Los colaboradores que lo tengan asignado no se modifican automáticamente.',
        ]);
    }

    /**
     * Genera un código consecutivo y único a nivel PLATAFORMA (SER-0001, …).
     * `Servicio` no tiene `empresa_id` propio (se deriva del contrato), así
     * que usa el generador global — mismo motivo exacto que `Almacen`.
     */
    private function generarCodigo(): string
    {
        return $this->codigos->siguiente('servicio', 'SER', semilla: fn (): int => $this->maximoSufijo(
            Servicio::query()->where('codigo', 'like', 'SER-%')->pluck('codigo'),
            'SER-',
        ));
    }

    /**
     * Previsualización NO autoritativa del siguiente código de servicio — no
     * reserva el consecutivo.
     */
    public function siguienteCodigo(Request $request): JsonResponse
    {
        $this->authorize('create', Servicio::class);

        $codigo = $this->codigos->siguienteAproximado('servicio', 'SER', semilla: fn (): int => $this->maximoSufijo(
            Servicio::query()->where('codigo', 'like', 'SER-%')->pluck('codigo'),
            'SER-',
        ));

        return response()->json(['codigo' => $codigo]);
    }
}
