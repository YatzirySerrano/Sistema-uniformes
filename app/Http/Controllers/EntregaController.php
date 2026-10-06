<?php

namespace App\Http\Controllers;

use App\Acciones\ConfirmarAcuseRecepcion;
use App\Acciones\RegistrarEntregaFirmada;
use App\Acciones\ReservarInventarioEntrega;
use App\Enums\EstadoEntrega;
use App\Enums\FinalidadCustodia;
use App\Enums\TipoReserva;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Controllers\Concerns\ExportaListado;
use App\Http\Requests\Entregas\GuardarEntregaRequest;
use App\Http\Requests\Entregas\GuardarIdentidadEntregaRequest;
use App\Models\CambioServicioColaborador;
use App\Models\Colaborador;
use App\Models\DetalleEntrega;
use App\Models\Devolucion;
use App\Models\EntregaUniforme;
use App\Models\Evidencia;
use App\Models\UnidadActivo;
use App\Models\User;
use App\Servicios\ServicioCustodiaColaborador;
use App\Servicios\ServicioEvidencias;
use App\Servicios\ServicioIdentidadColaborador;
use App\Servicios\ServicioReservas;
use App\Soporte\ContextoExportacion;
use App\Soporte\FechaHora;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Entregas de activos. La empresa y la sucursal se DERIVAN del colaborador
 * (contexto/histórico, no dimensión de stock); el almacén de origen se elige
 * explícitamente en el formulario (`ResolverAlmacenOperativo` sólo valida esa
 * elección o preselecciona cuando es inequívoca). La entrega combina activos
 * sueltos, unidades de seguimiento individual y conjuntos.
 */
class EntregaController extends Controller
{
    use ConEmpresa;
    use ExportaListado;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', EntregaUniforme::class);

        $filtros = $this->filtrosListado($request);
        $empresaFiltro = $this->empresaDelFiltro($request);
        $colaboradorFiltro = ($filtros['colaborador_id'] ?? null)
            ? Colaborador::query()->whereKey($filtros['colaborador_id'])->first(['id', 'nombre_completo', 'numero_empleado'])
            : null;

        $entregas = $this->consultaEntregas($request)
            ->withCount('detalles')
            ->latest()
            ->paginate($this->porPagina())
            ->withQueryString()
            ->through(fn (EntregaUniforme $e): array => [
                'id' => $e->id,
                'folio' => $e->folio,
                'empresa' => $e->empresa?->nombre_comercial,
                'colaborador' => $e->colaborador?->nombre_completo,
                'numero_empleado' => $e->colaborador?->numero_empleado,
                'sucursal' => $e->sucursal?->nombre,
                'encargado' => $e->encargado?->name,
                'servicio' => $e->servicio === null ? null : $e->servicio->contrato->nombre.' — '.$e->servicio->nombre,
                'estado' => $e->estado->value,
                'estado_etiqueta' => $e->estado->etiqueta(),
                'fecha_entrega' => $e->fecha_entrega->toDateString(),
                'renglones' => $e->detalles_count,
            ]);

        return Inertia::render('Entregas/Index', [
            'entregas' => $entregas,
            'filtros' => [...$filtros, 'empresa_id' => $empresaFiltro?->id],
            // Cuando se llega desde "Activos asignados"/"Entregas" del perfil
            // de un colaborador (`?colaborador_id=`), el listado queda
            // filtrado por su ID real (nunca por nombre) y el frontend puede
            // mostrar de forma visible "filtrado por {colaborador}".
            'colaboradorFiltro' => $colaboradorFiltro,
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
            'estados' => collect(EstadoEntrega::cases())->map(fn ($e): array => ['valor' => $e->value, 'etiqueta' => $e->etiqueta()]),
            'puedeCrear' => $request->user()->can('create', EntregaUniforme::class),
        ]);
    }

    /**
     * Excel/PDF del listado, respetando los mismos filtros/alcance que
     * `index()` (misma consulta base, nunca una aparte) — diseño
     * administrativo compartido (`ExportaListado`/`ListadoExport`).
     */
    public function exportar(Request $request): BinaryFileResponse|HttpResponse
    {
        $this->authorize('viewAny', EntregaUniforme::class);

        $filtros = $this->filtrosListado($request);
        $empresaFiltro = $this->empresaDelFiltro($request);

        $entregas = $this->consultaEntregas($request)
            ->withCount('detalles')
            ->latest()
            ->get();

        $filas = $entregas->map(fn (EntregaUniforme $e): array => [
            $e->folio,
            $e->colaborador?->nombre_completo,
            $e->colaborador?->numero_empleado,
            $e->empresa?->nombre_comercial,
            $e->sucursal?->nombre,
            $e->servicio === null ? null : $e->servicio->contrato->nombre.' — '.$e->servicio->nombre,
            $e->fecha_entrega->format('d/m/Y'),
            $e->estado->etiqueta(),
            (int) $e->detalles_count,
            $e->encargado?->name,
        ])->all();

        $filtrosHumanos = array_filter([
            'Búsqueda' => $filtros['buscar'] ?? null,
            'Estado' => ($filtros['estado'] ?? null) ? (EstadoEntrega::tryFrom($filtros['estado'])?->etiqueta() ?? $filtros['estado']) : null,
            'Desde' => ($filtros['desde'] ?? null) ? Carbon::parse($filtros['desde'])->format('d/m/Y') : null,
            'Hasta' => ($filtros['hasta'] ?? null) ? Carbon::parse($filtros['hasta'])->format('d/m/Y') : null,
        ]);

        $contexto = new ContextoExportacion(
            'Entregas',
            $empresaFiltro,
            $filtrosHumanos,
            $entregas->count(),
            generadoPor: $request->user()?->name,
            kpis: $this->kpisEntregasListado($entregas),
        );

        return $this->respuestaExportacion($request->input('formato', 'xlsx'), $filas, [
            'Folio', 'Colaborador', 'N.º empleado', 'Empresa', 'Sucursal', 'Servicio', 'Fecha', 'Estado', 'Renglones', 'Responsable',
        ], $contexto);
    }

    /**
     * @param  Collection<int, EntregaUniforme>  $entregas
     * @return array<string, string|int>
     */
    private function kpisEntregasListado(Collection $entregas): array
    {
        return [
            'Entregas' => $entregas->count(),
            'Renglones' => $entregas->sum('detalles_count'),
            'Firmadas' => $entregas->filter(fn (EntregaUniforme $e): bool => $e->estado->estaFirmada())->count(),
            'Pendientes de firma' => $entregas->filter(fn (EntregaUniforme $e): bool => $e->estado === EstadoEntrega::PendienteFirma)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function filtrosListado(Request $request): array
    {
        return $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'empresa_id' => ['nullable', 'integer'],
            'sucursal_id' => ['nullable', 'integer'],
            'almacen_id' => ['nullable', 'integer'],
            'colaborador_id' => ['nullable', 'integer'],
            'estado' => ['nullable', 'string'],
            'contrato_id' => ['nullable', 'integer'],
            'servicio_id' => ['nullable', 'integer'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
        ]);
    }

    /**
     * Consulta filtrada compartida por `index()` (pagina + `through()`) y
     * `exportar()` (`get()` + `map()`), acotada al alcance de empresas del
     * usuario. Nunca pagina ni hace `withCount`/`latest` aquí: cada llamador
     * decide eso según lo que necesite.
     *
     * @return Builder<EntregaUniforme>
     */
    private function consultaEntregas(Request $request): Builder
    {
        $idsAutorizadas = $this->idsEmpresasAutorizadas($request);
        $empresaFiltro = $this->empresaDelFiltro($request);
        $filtros = $this->filtrosListado($request);

        return EntregaUniforme::query()
            ->whereIn('empresa_id', $idsAutorizadas)
            ->when($empresaFiltro !== null, fn (Builder $q) => $q->where('empresa_id', $empresaFiltro->id))
            ->when($filtros['buscar'] ?? null, fn (Builder $q, $b) => $q->where(fn (Builder $s) => $s
                ->where('folio', 'like', "%{$b}%")
                ->orWhereHas('colaborador', fn (Builder $c) => $c->where('nombre_completo', 'like', "%{$b}%")->orWhere('numero_empleado', 'like', "%{$b}%"))))
            ->when($filtros['sucursal_id'] ?? null, fn (Builder $q, $s) => $q->where('sucursal_id', $s))
            ->when($filtros['almacen_id'] ?? null, fn (Builder $q, $a) => $q->where('almacen_id', $a))
            // Igual que `sucursal_id`: `colaborador_id` sólo puede devolver
            // filas ya acotadas por `empresa_id` arriba — un colaborador de
            // otra empresa nunca filtra nada ajeno.
            ->when($filtros['colaborador_id'] ?? null, fn (Builder $q, $c) => $q->where('colaborador_id', $c))
            ->when($filtros['estado'] ?? null, fn (Builder $q, $e) => $q->where('estado', $e))
            // Servicio es el snapshot histórico de la propia entrega
            // (`entregas_uniformes.servicio_id`); Contrato filtra por el
            // contrato de ese mismo servicio — ninguno de los dos usa el
            // servicio VIGENTE del colaborador, que puede ya haber cambiado.
            ->when($filtros['servicio_id'] ?? null, fn (Builder $q, $s) => $q->where('servicio_id', $s))
            ->when($filtros['contrato_id'] ?? null, fn (Builder $q, $c) => $q->whereHas('servicio', fn (Builder $sq) => $sq->where('contrato_id', $c)))
            ->when($filtros['desde'] ?? null, fn (Builder $q, $d) => $q->whereDate('fecha_entrega', '>=', $d))
            ->when($filtros['hasta'] ?? null, fn (Builder $q, $h) => $q->whereDate('fecha_entrega', '<=', $h))
            ->with(['colaborador:id,nombre_completo,numero_empleado', 'sucursal:id,nombre', 'empresa:id,nombre_comercial', 'encargado:id,name', 'servicio:id,nombre,contrato_id', 'servicio.contrato:id,nombre']);
    }

    public function create(Request $request, ServicioCustodiaColaborador $custodia): Response
    {
        $this->authorize('create', EntregaUniforme::class);

        $usuario = $request->user();
        $puedeRedistribuir = $usuario->can('redistribuir', EntregaUniforme::class);

        // Redistribución en nombre de un colaborador dentro de la revisión de
        // su cambio de servicio (`?cambio_servicio=`): la custodia es la de
        // ÉL, no la del usuario, y sólo con permiso efectivo para hacerlo.
        $contexto = null;
        if ($request->filled('cambio_servicio')) {
            $cambio = CambioServicioColaborador::query()
                ->with(['colaborador.empresa:id,codigo,nombre_comercial', 'colaborador.sucursal:id,nombre'])
                ->findOrFail($request->integer('cambio_servicio'));
            $this->authorize('redistribuirCustodia', $cambio);

            $destinatario = $request->filled('destinatario')
                ? Colaborador::query()
                    ->where('empresa_id', $cambio->empresa_id)
                    ->where('activo', true)
                    ->with(['sucursal:id,nombre', 'servicioActual:id,nombre,contrato_id', 'servicioActual.contrato:id,nombre'])
                    ->find($request->integer('destinatario'))
                : null;

            $contexto = [
                'id' => $cambio->id,
                'custodio' => $this->opcionCustodio($cambio->colaborador),
                'destinatario' => $destinatario === null ? null : [
                    'id' => $destinatario->id,
                    'nombre_completo' => $destinatario->nombre_completo,
                    'numero_empleado' => $destinatario->numero_empleado,
                    'empresa_id' => $destinatario->empresa_id,
                    'sucursal_id' => $destinatario->sucursal_id,
                    'sucursal' => $destinatario->sucursal === null ? null : ['id' => $destinatario->sucursal->id, 'nombre' => $destinatario->sucursal->nombre],
                    'servicio_actual' => $destinatario->servicioActual === null ? null : [
                        'id' => $destinatario->servicioActual->id,
                        'nombre' => $destinatario->servicioActual->nombre,
                        'contrato' => ['id' => $destinatario->servicioActual->contrato->id, 'nombre' => $destinatario->servicioActual->contrato->nombre],
                    ],
                ],
            ];
        }

        // "Mi custodia" = la ficha de colaborador vinculada a esta cuenta.
        // Sin vínculo no se ofrece como origen válido (nunca se adivina).
        $vinculado = $puedeRedistribuir ? $custodia->colaboradorVinculado($usuario) : null;

        return Inertia::render('Entregas/Crear', [
            // Vías disponibles para ESTE usuario según sus permisos efectivos
            // y su vínculo con una ficha de colaborador — nunca por rol.
            'origenes' => [
                'almacen' => $contexto === null && $usuario->can('entregarDesdeAlmacen', EntregaUniforme::class),
                'custodia' => $contexto !== null || $vinculado !== null,
            ],
            // Tiene el permiso de redistribuir pero su cuenta no representa a
            // ninguna ficha: la pantalla lo explica en vez de ofrecerlo.
            'redistribuirSinVinculo' => $contexto === null && $puedeRedistribuir && $vinculado === null,
            'custodiaPropia' => $vinculado === null ? null : $this->opcionCustodio($vinculado),
            'contextoCambioServicio' => $contexto,
            // El flujo único termina SIEMPRE en la firma dentro de la misma
            // pantalla; el encargado que firma es el usuario autenticado.
            'encargado' => [
                'name' => $request->user()->name,
                'email' => $request->user()->email,
            ],
            'textoConsentimiento' => ConfirmarAcuseRecepcion::TEXTO_CONSENTIMIENTO,
            // Fecha de negocio "de hoy" en la zona de presentación — sólo para
            // MOSTRARLA de forma no editable (evita el salto de día de
            // `toISOString()` en UTC cerca de medianoche). El valor guardado lo
            // decide siempre el servidor al confirmar, no este prop.
            'fechaActual' => FechaHora::hoyNegocio(),
        ]);
    }

    /**
     * Existencias EFECTIVAS por cantidad (empresa+almacén, por activo+talla):
     * saldo real menos lo que otras reservas activas ya apartaron (la propia
     * reserva del borrador, si se manda `token`, nunca se descuenta de sí
     * misma). El formulario la consulta periódicamente (`?activo_ids[]=`
     * acota a los activos en pantalla): es una LECTURA pura — nunca crea,
     * renueva ni libera apartados. Aviso de UX; el backend siempre revalida
     * con bloqueo al registrar/reservar.
     */
    public function disponibilidad(Request $request, ServicioReservas $reservas): JsonResponse
    {
        $this->authorize('entregarDesdeAlmacen', EntregaUniforme::class);

        $datos = $request->validate([
            'empresa_id' => ['required', 'integer'],
            'almacen_id' => ['required', 'integer'],
            'token' => ['nullable', 'uuid'],
            'activo_ids' => ['nullable', 'array', 'max:200'],
            'activo_ids.*' => ['integer'],
        ]);

        if (! $request->user()->puedeAccederEmpresa((int) $datos['empresa_id'])) {
            return response()->json(['saldos' => []]);
        }

        $saldos = $reservas->disponibilidadEfectivaEnAlmacen(
            TipoReserva::Entrega,
            (int) $datos['empresa_id'],
            (int) $datos['almacen_id'],
            isset($datos['activo_ids']) ? array_map('intval', $datos['activo_ids']) : null,
            $datos['token'] ?? null,
        );

        return response()->json(['saldos' => $saldos]);
    }

    /**
     * Recalcula, de forma atómica, el apartado temporal de TODO el borrador
     * de Entrega actual (ver `App\Acciones\ReservarInventarioEntrega`). Se
     * llama cada vez que el usuario termina de editar una selección válida
     * del paso 2 (debounce en el frontend) — nunca al confirmar; confirmar
     * usa `App\Http\Controllers\EntregaController::store()` como siempre.
     */
    public function reservar(Request $request, ReservarInventarioEntrega $accion): JsonResponse
    {
        $this->authorize('entregarDesdeAlmacen', EntregaUniforme::class);

        $datos = $request->validate([
            'token' => ['required', 'uuid'],
            'empresa_id' => ['required', 'integer'],
            'almacen_id' => ['required', 'integer'],
            'colaborador_id' => ['nullable', 'integer'],
            // Reglas LAXAS a propósito: este endpoint se llama en caliente
            // mientras el usuario todavía está editando el paso 2 (renglones
            // a medio llenar, con `activo_id: ''` para una fila sin elegir
            // todavía) — el Accion filtra/ignora lo incompleto, nunca 422
            // sólo por un renglón en blanco.
            'activos' => ['nullable', 'array'],
            'activos.*.activo_id' => ['present'],
            'activos.*.talla_id' => ['nullable'],
            'activos.*.cantidad' => ['present'],
            'unidades' => ['nullable', 'array'],
            'unidades.*.unidad_activo_id' => ['present'],
            'conjuntos' => ['nullable', 'array'],
            'conjuntos.*.conjunto_id' => ['present'],
            'conjuntos.*.cantidad' => ['present'],
            'conjuntos.*.variantes' => ['nullable', 'array'],
        ]);

        abort_unless($request->user()->puedeAccederEmpresa((int) $datos['empresa_id']), 403);

        $resultado = $accion->ejecutar(
            $datos['token'],
            $request->user()->id,
            (int) $datos['empresa_id'],
            (int) $datos['almacen_id'],
            isset($datos['colaborador_id']) ? (int) $datos['colaborador_id'] : null,
            $datos['activos'] ?? [],
            $datos['unidades'] ?? [],
            $datos['conjuntos'] ?? [],
        );

        return response()->json($resultado);
    }

    /**
     * Libera explícitamente la reserva del borrador (cancelar, cambiar de
     * almacén, quitar el último renglón…). No falla si ya venció o no existe
     * — liberar algo que ya no bloquea nada es un no-op válido.
     */
    public function liberarReserva(Request $request, string $token, ServicioReservas $reservas): JsonResponse
    {
        // Liberar sólo toca apartados PROPIOS (`user_id`) y nunca aparta nada:
        // basta con poder registrar entregas de cualquier origen. Así la
        // limpieza al salir del formulario no devuelve 403 a quien sólo
        // redistribuye desde su custodia.
        $this->authorize('create', EntregaUniforme::class);
        $reservas->liberar($token, $request->user()->id, TipoReserva::Entrega);

        return response()->json(['ok' => true]);
    }

    /**
     * Extensión EXPLÍCITA de +10 minutos, pedida por el usuario desde el
     * countdown — nunca una renovación automática en segundo plano.
     */
    public function extenderReserva(Request $request, string $token, ServicioReservas $reservas): JsonResponse
    {
        $this->authorize('entregarDesdeAlmacen', EntregaUniforme::class);
        $reserva = $reservas->extender($token, $request->user()->id, TipoReserva::Entrega);

        return response()->json(['token' => $reserva->token, 'expira_en' => $reserva->expira_en->toIso8601String()]);
    }

    /**
     * Activos por cantidad o de seguimiento individual que el usuario tiene
     * HOY bajo su custodia en la empresa indicada — con el mismo formato que
     * `activos/buscar` para reutilizar el paso 2 del wizard. La consulta ya
     * sale acotada a la custodia (nunca "traer todo y filtrar en Vue").
     */
    public function custodiaActivos(Request $request, ServicioCustodiaColaborador $custodia): JsonResponse
    {
        $this->authorize('redistribuir', EntregaUniforme::class);

        $custodio = $this->custodioDesdeRequest($request, $custodia);
        if ($custodio === null) {
            return response()->json(['activos' => []]);
        }

        $incluirPersonales = $this->incluirPersonalesDesdeRequest($request);
        $termino = Str::lower(trim((string) $request->query('q', '')));
        $control = $request->query('control') === 'individual' ? 'individual' : 'cantidad';

        // Una opción por activo + VARIANTE + bolsa ("para redistribuir" / "uso
        // personal o sin clasificar"): el mismo activo en dos bolsas (o dos
        // tallas) aparece como opciones distintas y autoexplicativas — nunca
        // "Pantalón" dos veces sin decir cuál es cuál. `id` es sintético y
        // único; `activo_id` es el real que se envía al guardar.
        $opcion = fn (int $activoId, string $bolsa, ?int $tallaId, array $datos): array => [
            'id' => (($activoId * 1_000_000) + ($tallaId ?? 0)) * 2 + ($bolsa === ServicioCustodiaColaborador::BOLSA_PERSONAL ? 1 : 0),
            'activo_id' => $activoId,
            'bolsa' => $bolsa,
            'bolsa_etiqueta' => $bolsa === ServicioCustodiaColaborador::BOLSA_PERSONAL ? 'Uso personal / sin clasificar' : 'Para redistribuir',
            'codigo' => null,
            'tipo' => null,
            'categoria' => null,
            ...$datos,
        ];

        if ($control === 'individual') {
            $activos = $custodia->unidadesRedistribuibles($custodio, $incluirPersonales)
                ->with('activo:id,nombre,codigo')
                ->get()
                ->groupBy(fn (UnidadActivo $u): string => $u->activo_id.'-'.$custodia->bolsaDeUnidad($u))
                ->map(fn (Collection $grupo, string $clave): array => $opcion((int) $grupo->first()->activo_id, explode('-', $clave, 2)[1], null, [
                    'nombre' => $grupo->first()->activo->nombre,
                    'codigo' => $grupo->first()->activo->codigo,
                    'control' => 'individual',
                    'usa_variantes' => false,
                    'tallas' => [],
                    'disponible' => $grupo->count(),
                ]));
        } else {
            // `talla_fija`: la variante ya viene decidida por la opción (el
            // renglón no vuelve a pedir talla). `tallas` conserva la forma
            // de siempre (una sola) para el resto del formulario.
            $activos = collect($custodia->cantidadesRedistribuibles($custodio, $incluirPersonales))
                ->map(fn (array $f): array => $opcion((int) $f['activo_id'], (string) $f['bolsa'], $f['talla_id'], [
                    'nombre' => $f['activo'],
                    'control' => 'cantidad',
                    'usa_variantes' => $f['talla_id'] !== null,
                    'talla_fija' => $f['talla_id'] === null ? null : ['id' => (int) $f['talla_id'], 'valor' => (string) $f['talla']],
                    'tallas' => $f['talla_id'] === null ? [] : [[
                        'id' => (int) $f['talla_id'],
                        'valor' => (string) $f['talla'],
                        'disponible' => $f['disponible'],
                    ]],
                    'disponible' => $f['disponible'],
                ]));
        }

        $resultado = $activos
            ->when($termino !== '', fn (Collection $c) => $c->filter(fn (array $a): bool => str_contains(Str::lower($a['nombre'].' '.($a['codigo'] ?? '')), $termino)))
            // Primero lo que es para redistribuir; lo personal después.
            ->sortBy(fn (array $a): string => ($a['bolsa'] === ServicioCustodiaColaborador::BOLSA_PERSONAL ? '1' : '0').$a['nombre'].'|'.($a['talla_fija']['valor'] ?? ''))
            ->take(30)
            ->values();

        return response()->json(['activos' => $resultado]);
    }

    /**
     * ¿Los selectores de custodia pueden ofrecer la bolsa de uso personal?
     * Sólo con `entregas.redistribuir-propios`, o dentro de la revisión de
     * un cambio de servicio (su custodio ya se validó en `custodioDesdeRequest`).
     */
    private function incluirPersonalesDesdeRequest(Request $request): bool
    {
        return $request->filled('cambio_servicio_id')
            || $request->user()->can('redistribuirPropios', EntregaUniforme::class);
    }

    /**
     * Unidades identificadas de un activo que están HOY bajo la custodia del
     * usuario (mismo formato que `activos/unidades/buscar`).
     */
    public function custodiaUnidades(Request $request, ServicioCustodiaColaborador $custodia): JsonResponse
    {
        $this->authorize('redistribuir', EntregaUniforme::class);

        $custodio = $this->custodioDesdeRequest($request, $custodia);
        $activoId = (int) $request->query('activo_id', 0);

        if ($custodio === null || $activoId <= 0) {
            return response()->json(['unidades' => []]);
        }

        $termino = trim((string) $request->query('q', ''));

        $bolsa = (string) $request->query('bolsa', ServicioCustodiaColaborador::BOLSA_REDISTRIBUCION);
        $incluirPersonales = $this->incluirPersonalesDesdeRequest($request);

        if ($bolsa === ServicioCustodiaColaborador::BOLSA_PERSONAL && ! $incluirPersonales) {
            return response()->json(['unidades' => []]);
        }

        $unidades = $custodia->soloBolsa($custodia->unidadesRedistribuibles($custodio, true), $bolsa === ServicioCustodiaColaborador::BOLSA_PERSONAL ? ServicioCustodiaColaborador::BOLSA_PERSONAL : ServicioCustodiaColaborador::BOLSA_REDISTRIBUCION)
            ->where('activo_id', $activoId)
            ->when($termino !== '', fn (Builder $q) => $q->where('codigo', 'like', "%{$termino}%"))
            ->with(['activo:id,nombre', 'especificacion'])
            ->orderBy('codigo')
            ->limit(30)
            ->get()
            ->map(fn (UnidadActivo $u): array => [
                'id' => $u->id,
                'codigo' => $u->codigo,
                'activo' => $u->activo?->nombre,
                'marca_modelo' => $u->especificacion?->marcaModelo(),
                'imei_mascara' => $u->especificacion?->imeiMascara(),
                'numero_telefonico' => $u->especificacion?->numero_telefonico,
                'entregable' => true,
                'motivo_no_entregable' => null,
            ]);

        return response()->json(['unidades' => $unidades]);
    }

    /**
     * Disponible EN CUSTODIA por activo + variante (mismo formato que
     * `entregas/disponibilidad`), para los avisos del paso 2.
     */
    public function custodiaDisponibilidad(Request $request, ServicioCustodiaColaborador $custodia): JsonResponse
    {
        $this->authorize('redistribuir', EntregaUniforme::class);

        $custodio = $this->custodioDesdeRequest($request, $custodia);

        if ($custodio === null) {
            return response()->json(['saldos' => [], 'custodio' => null]);
        }

        $incluirPersonales = $this->incluirPersonalesDesdeRequest($request);
        $saldos = collect($custodia->cantidadesRedistribuibles($custodio, $incluirPersonales))
            ->map(fn (array $f): array => [
                'activo_id' => $f['activo_id'],
                'talla_id' => $f['talla_id'],
                'bolsa' => $f['bolsa'],
                'disponible' => $f['disponible'],
            ])
            ->values();

        return response()->json([
            'saldos' => $saldos,
            'custodio' => ['id' => $custodio->id, 'nombre_completo' => $custodio->nombre_completo],
            // Para el estado vacío: sin piezas NI unidades no hay nada que
            // entregar (nunca se cae al inventario del almacén).
            'total_unidades' => $custodia->unidadesRedistribuibles($custodio, $incluirPersonales)->count(),
        ]);
    }

    /**
     * Conjuntos recibidos que el usuario (o el colaborador revisado) todavía
     * tiene, con el desglose real por componente y cuántos están completos.
     */
    public function custodiaConjuntos(Request $request, ServicioCustodiaColaborador $custodia): JsonResponse
    {
        $this->authorize('redistribuir', EntregaUniforme::class);

        $custodio = $this->custodioDesdeRequest($request, $custodia);

        if ($custodio === null) {
            return response()->json(['conjuntos' => []]);
        }

        $termino = Str::lower(trim((string) $request->query('q', '')));

        $conjuntos = array_values(array_map(
            fn (array $f): array => ['id' => $f['conjunto_id'], ...$f],
            array_filter(
                $custodia->conjuntosRedistribuibles($custodio),
                fn (array $f): bool => $termino === '' || str_contains(Str::lower($f['nombre']), $termino),
            ),
        ));

        return response()->json(['conjuntos' => $conjuntos]);
    }

    /**
     * Custodio (origen) del usuario autenticado: su propio registro de
     * colaborador, sea cual sea la empresa DESTINO `?empresa_id=` elegida
     * (debe estar autorizada). Cambiar la empresa destino nunca cambia de
     * quién salen los bienes. Nunca se acepta un colaborador arbitrario desde
     * el request.
     */
    private function custodioDesdeRequest(Request $request, ServicioCustodiaColaborador $custodia): ?Colaborador
    {
        $empresaId = (int) $request->query('empresa_id', 0);

        if ($request->filled('cambio_servicio_id')) {
            $cambio = CambioServicioColaborador::query()->with('colaborador.sucursal')->find($request->integer('cambio_servicio_id'));

            return $cambio !== null
                && $request->user()->can('redistribuirCustodia', $cambio)
                && $cambio->empresa_id === $empresaId
                    ? $cambio->colaborador
                    : null;
        }

        return $empresaId > 0 ? $custodia->custodioDeUsuario($request->user(), $empresaId) : null;
    }

    /**
     * @return array{colaborador_id: int, nombre_completo: string, empresa: array{id: int, codigo: string|null, nombre_comercial: string|null}}
     */
    private function opcionCustodio(Colaborador $colaborador): array
    {
        $colaborador->loadMissing('empresa:id,codigo,nombre_comercial');

        return [
            'colaborador_id' => $colaborador->id,
            'nombre_completo' => $colaborador->nombre_completo,
            'empresa' => [
                'id' => $colaborador->empresa_id,
                'codigo' => $colaborador->empresa?->codigo,
                'nombre_comercial' => $colaborador->empresa?->nombre_comercial,
            ],
        ];
    }

    /**
     * Búsqueda de entregas para originar una devolución (`Devoluciones/Crear`
     * sin `?entrega_id=` precargado). Devuelve SÓLO entregas de las empresas
     * autorizadas que todavía tienen custodia pendiente real (no basta con
     * "no anulada": una entrega totalmente devuelta y confirmada no debe
     * volver a ofrecerse — ver `ServicioCustodiaColaborador::filtrarConPendiente()`,
     * fuente única de este cálculo).
     */
    public function buscar(Request $request, ServicioCustodiaColaborador $custodia): JsonResponse
    {
        $this->authorize('create', Devolucion::class);

        $idsAutorizadas = $this->idsEmpresasAutorizadas($request);
        $termino = trim((string) $request->query('q', ''));

        $entregas = $custodia->filtrarConPendiente(
            EntregaUniforme::query()
                ->whereIn('empresa_id', $idsAutorizadas)
                ->where('estado', '!=', EstadoEntrega::Anulada)
                ->when($termino !== '', fn ($q) => $q->where(fn ($s) => $s
                    ->where('folio', 'like', "%{$termino}%")
                    ->orWhereHas('colaborador', fn ($c) => $c->where('nombre_completo', 'like', "%{$termino}%")->orWhere('numero_empleado', 'like', "%{$termino}%"))))
        )
            ->with(['colaborador:id,nombre_completo,numero_empleado', 'empresa:id,nombre_comercial'])
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (EntregaUniforme $e): array => [
                'id' => $e->id,
                'folio' => $e->folio,
                'colaborador' => $e->colaborador?->nombre_completo,
                'numero_empleado' => $e->colaborador?->numero_empleado,
                'empresa' => $e->empresa?->nombre_comercial,
                'fecha_entrega' => $e->fecha_entrega->toDateString(),
            ]);

        return response()->json(['entregas' => $entregas]);
    }

    /**
     * Flujo ÚNICO: registrar y firmar en una sola operación. La petición trae
     * ya las dos firmas y la aceptación; `RegistrarEntregaFirmada` crea la
     * entrega, descuenta inventario y la deja FIRMADA dentro de una única
     * transacción — si algo falla, se revierte todo y no queda una entrega
     * "pendiente de firma". El PDF y el correo se materializan tras el commit.
     */
    public function store(GuardarEntregaRequest $request, RegistrarEntregaFirmada $accion, ServicioEvidencias $evidenciasSvc): RedirectResponse
    {
        $colaborador = Colaborador::findOrFail($request->integer('colaborador_id'));
        abort_unless($request->user()->puedeAccederEmpresa($colaborador->empresa_id), 403, 'No tienes acceso a la empresa de ese colaborador.');

        $datos = $request->validated();

        // Redistribución: el origen es SIEMPRE la custodia del colaborador
        // ligado al usuario autenticado (resuelta en el backend); nunca un
        // custodio que venga del formulario.
        $custodioOrigenId = $request->esRedistribucion() ? $request->custodio()?->getKey() : null;
        $cambioServicio = $request->esRedistribucion() ? $request->cambioServicio() : null;

        // Idempotencia: un doble submit o un reintento de red no debe registrar
        // dos entregas. La clave la genera el formulario (una por intento).
        $clave = $datos['idempotency_key'] ?? null;
        if ($clave !== null && ! Cache::add("entregas:idempotencia:{$clave}", true, now()->addMinutes(10))) {
            throw new ExcepcionDeNegocioSimple('Esta entrega ya se registró o se está procesando. Revisa el listado de entregas.');
        }

        // Evidencia fotográfica OPCIONAL por renglón: se guarda en disco privado
        // ANTES de la transacción (mismo patrón que la firma). `$metasEvidencia`
        // permite borrar los archivos huérfanos si la operación falla.
        $evidencias = [];
        $metasEvidencia = [];
        try {
            foreach (array_keys($datos['activos'] ?? []) as $i) {
                $archivo = $request->file("activos.{$i}.evidencia");
                if ($archivo !== null) {
                    $meta = $evidenciasSvc->guardarPendiente($archivo, "evidencias/entregas/{$colaborador->empresa_id}", $datos['activos'][$i]['evidencia_origen'] ?? 'archivo');
                    $evidencias["activo:{$i}"] = $meta;
                    $metasEvidencia[] = $meta;
                }
            }
            foreach (array_keys($datos['unidades'] ?? []) as $i) {
                $archivo = $request->file("unidades.{$i}.evidencia");
                if ($archivo !== null) {
                    $meta = $evidenciasSvc->guardarPendiente($archivo, "evidencias/entregas/{$colaborador->empresa_id}", $datos['unidades'][$i]['evidencia_origen'] ?? 'archivo');
                    $evidencias["unidad:{$i}"] = $meta;
                    $metasEvidencia[] = $meta;
                }
            }
        } catch (Throwable $e) {
            $evidenciasSvc->descartar($metasEvidencia);
            if ($clave !== null) {
                Cache::forget("entregas:idempotencia:{$clave}");
            }

            throw $e;
        }

        try {
            $acuse = $accion->ejecutar(
                $colaborador->id,
                $custodioOrigenId === null ? (int) $datos['almacen_id'] : null,
                $request->user()->id,
                // Fecha AUTORITATIVA: siempre "hoy" del servidor, nunca lo que
                // mande el cliente — una entrega nueva no puede fecharse en el
                // pasado ni en el futuro manipulando el payload.
                FechaHora::hoyNegocio(),
                $datos['activos'] ?? [],
                $datos['unidades'] ?? [],
                $datos['conjuntos'] ?? [],
                $datos['notas'] ?? null,
                // Snapshot histórico del servicio: SIEMPRE el servicio operativo
                // vigente del colaborador, resuelto en el backend. Nunca se
                // confía en un `servicio_id` del formulario (no existe ya). Si
                // el colaborador no tiene servicio (personal administrativo),
                // la entrega se registra igual con snapshot nulo.
                $colaborador->servicio_actual_id,
                // Firma de quien recibe: dibujada (`firma`) o archivo subido
                // (`firma_archivo`, firma a distancia) — nunca ambas.
                $request->firmaPorArchivo() ? '' : (string) ($datos['firma'] ?? ''),
                $datos['firma_operador'],
                true, // aceptación (validada por la regla `accepted`)
                $request->ip(),
                $request->userAgent(),
                $evidencias,
                $custodioOrigenId === null ? ($datos['reserva_token'] ?? null) : null,
                $custodioOrigenId,
                $request->incluirPersonales(),
                $request->firmaPorArchivo() ? $request->file('firma_archivo') : null,
                // Salida de almacén: empresa PROPIETARIA del inventario (puede
                // no ser la del colaborador destino; ya autorizada en el request).
                $custodioOrigenId === null ? $request->empresaInventarioId() : null,
            );
        } catch (Throwable $e) {
            // Falló: se libera la clave para permitir un reintento legítimo y se
            // borran los archivos de evidencia huérfanos.
            if ($clave !== null) {
                Cache::forget("entregas:idempotencia:{$clave}");
            }
            $evidenciasSvc->descartar($metasEvidencia);

            throw $e;
        }

        $entrega = $acuse->entrega;

        if ($cambioServicio !== null) {
            return to_route('cambios-servicio.show', $cambioServicio)->with('toast', [
                'type' => 'success',
                'message' => "Redistribución {$entrega->folio} firmada. Revisa el estado de la custodia pendiente.",
            ]);
        }

        return to_route('entregas.show', $entrega)->with('toast', [
            'type' => 'success',
            'message' => "Entrega {$entrega->folio} registrada y confirmada correctamente."
                .' El comprobante se enviará por correo a las partes con correo registrado.',
        ]);
    }

    /**
     * Metadata del documento de identidad del colaborador para el paso de
     * firma de una entrega: sólo indica si hay una identificación registrada
     * y su URL de consulta privada. Autorización de mínimo privilegio (ver
     * `autorizarConsultaIdentidad()`).
     */
    public function documentoIdentidad(Request $request, Colaborador $colaborador, ServicioIdentidadColaborador $identidad): JsonResponse
    {
        $this->autorizarConsultaIdentidad($request->user(), $colaborador);

        $meta = $identidad->metadata($colaborador);

        if (! $meta['disponible']) {
            return response()->json($meta);
        }

        return response()->json([...$meta, 'url' => route('entregas.documento-identidad.ver', $colaborador)]);
    }

    /**
     * Sirve, en streaming y sólo tras validar la autorización, la ÚLTIMA
     * versión del documento de identidad del colaborador. Nunca expone la
     * ruta en disco ni genera una URL pública/predecible; se consulta
     * inline únicamente para la verificación visual del encargado.
     */
    public function verDocumentoIdentidad(Request $request, Colaborador $colaborador, ServicioIdentidadColaborador $identidad): StreamedResponse
    {
        $this->autorizarConsultaIdentidad($request->user(), $colaborador);

        return $identidad->streamDocumento($colaborador);
    }

    /**
     * Autorización de MÍNIMO PRIVILEGIO para consultar la identificación del
     * colaborador DURANTE una entrega: basta poder registrar entregas y tener
     * al colaborador dentro del alcance de empresa autorizado. NO concede
     * acceso a navegar ni descargar el resto del expediente (eso sigue
     * exigiendo `colaboradores.expediente-descargar`).
     */
    private function autorizarConsultaIdentidad(User $usuario, Colaborador $colaborador): void
    {
        abort_unless($usuario->can('create', EntregaUniforme::class), 403);
        abort_unless($usuario->puedeAccederEmpresa($colaborador->empresa_id), 403, 'No tienes acceso a la empresa de ese colaborador.');
    }

    public function show(Request $request, EntregaUniforme $entrega): Response
    {
        $this->authorize('view', $entrega);

        $entrega->load([
            'detalles.activo:id,nombre',
            'detalles.talla:id,valor',
            'detalles.unidadActivo:id,codigo,public_token,estado,condicion',
            'detalles.evidencias:id,evidenciable_id,evidenciable_type,mime,origen',
            'colaborador:id,nombre_completo,numero_empleado,usuario_id',
            'sucursal:id,nombre',
            'empresa:id,nombre_comercial',
            'almacen:id,nombre',
            'servicio:id,nombre,contrato_id',
            'servicio.contrato:id,nombre',
            'encargado:id,name',
            'acuse',
            'correcciones.corregidaPor:id,name',
            'colaboradorOrigen:id,nombre_completo,numero_empleado',
            'detalles.detalleOrigen.entrega:id,folio,colaborador_id,colaborador_origen_id',
            'detalles.detalleOrigen.entrega.colaborador:id,nombre_completo',
            'detalles.redistribuciones.entrega:id,folio,colaborador_id,estado',
            'detalles.redistribuciones.entrega.colaborador:id,nombre_completo',
        ]);

        return Inertia::render('Entregas/Detalle', [
            'entrega' => [
                'id' => $entrega->id,
                'folio' => $entrega->folio,
                'estado' => $entrega->estado->value,
                'estado_etiqueta' => $entrega->estado->etiqueta(),
                'fecha_entrega' => $entrega->fecha_entrega->toDateString(),
                'confirmada_en' => $entrega->confirmada_en?->toIso8601String(),
                'notas' => $entrega->notas,
                'empresa' => $entrega->empresa?->nombre_comercial,
                'almacen' => $entrega->almacen?->nombre,
                // Snapshot histórico: el servicio al MOMENTO de la entrega,
                // nunca se actualiza si el colaborador cambia de servicio
                // después. Null en entregas anteriores a este módulo.
                'servicio' => $entrega->servicio === null ? null : [
                    'nombre' => $entrega->servicio->nombre,
                    'contrato' => $entrega->servicio->contrato->nombre,
                ],
                'colaborador' => $entrega->colaborador?->only(['id', 'nombre_completo', 'numero_empleado']),
                // Redistribución de custodia: de quién salieron los bienes
                // (null = salida de almacén, ver `almacen`).
                'origen_custodia' => $entrega->colaboradorOrigen?->only(['id', 'nombre_completo', 'numero_empleado']),
                'sucursal' => $entrega->sucursal?->nombre,
                'encargado' => $entrega->encargado?->name,
                'items' => $entrega->detalles->map(fn ($d): array => [
                    'activo' => $d->activo_nombre_snapshot,
                    'talla' => $d->talla_valor_snapshot,
                    'cantidad' => $d->cantidad,
                    'evidencias' => $d->evidencias->map(fn (Evidencia $e): array => [
                        'url' => route('entregas.evidencias.ver', $e),
                        'mime' => $e->mime,
                    ])->all(),
                    'unidad_codigo' => $d->unidadActivo?->codigo,
                    'unidad_estado_visible' => $d->unidadActivo?->estadoVisible()->value,
                    'unidad_estado_visible_etiqueta' => $d->unidadActivo?->estadoVisible()->etiqueta(),
                    'conjunto' => $d->conjunto_nombre_snapshot,
                    // Finalidad tal como se guardó en ESTE renglón (null =
                    // "Sin clasificar"); nunca se agrupan renglones distintos.
                    'finalidad' => $d->finalidad?->value,
                    'finalidad_etiqueta' => FinalidadCustodia::etiquetaDe($d->finalidad),
                    // Cadena de custodia del renglón: de qué entrega anterior
                    // salió (redistribución) y a quién se redistribuyó después.
                    'recibido_de' => $d->detalleOrigen?->entrega === null ? null : [
                        'entrega_id' => $d->detalleOrigen->entrega->id,
                        'folio' => $d->detalleOrigen->entrega->folio,
                        'colaborador' => $d->detalleOrigen->entrega->colaborador?->nombre_completo,
                    ],
                    'redistribuido_a' => $d->redistribuciones
                        ->filter(fn (DetalleEntrega $hijo): bool => $hijo->entrega !== null && $hijo->entrega->estado !== EstadoEntrega::Anulada)
                        ->map(fn (DetalleEntrega $hijo): array => [
                            'entrega_id' => $hijo->entrega->id,
                            'folio' => $hijo->entrega->folio,
                            'colaborador' => $hijo->entrega->colaborador?->nombre_completo,
                            'cantidad' => (int) $hijo->cantidad,
                            // Cada eslabón se describe solo: variante (por
                            // cantidad) o código de unidad — nunca mezclados.
                            'talla' => $hijo->talla_valor_snapshot,
                            'unidad_codigo' => $hijo->unidad_activo_id === null ? null : $d->unidadActivo?->codigo,
                        ])->values()->all(),
                ]),
                'correcciones' => $entrega->correcciones->map(fn ($c): array => [
                    'id' => $c->id,
                    'motivo' => $c->motivo,
                    'por' => $c->corregidaPor?->name,
                    'fecha' => $c->created_at?->toIso8601String(),
                ]),
            ],
            'acuse' => $entrega->acuse === null ? null : [
                'id' => $entrega->acuse->id,
                'folio' => $entrega->acuse->folio,
                'firmado_en' => $entrega->acuse->firmado_en->toIso8601String(),
                'tiene_pdf' => $entrega->acuse->tienePdf(),
                // Cómo firmó quien recibe: dibujada en el pad o archivo subido
                // (firma a distancia; nombre original y tipo).
                'firma_metodo' => $entrega->acuse->metodoFirma(),
                'firma_archivo' => $entrega->acuse->firmaArchivo === null ? null : [
                    'nombre' => $entrega->acuse->firmaArchivo->nombre_original,
                    'es_pdf' => $entrega->acuse->firmaArchivo->esPdf(),
                ],
            ],
            'permisos' => [
                'firmar' => $request->user()->can('firmar', $entrega),
                'corregir' => $request->user()->can('corregir', $entrega),
                'ver_pdf' => $entrega->acuse !== null && $request->user()->can('verPdf', $entrega->acuse),
                'ver_firma' => $entrega->acuse !== null && $request->user()->can('verFirma', $entrega->acuse),
                'devolver' => $entrega->estado !== EstadoEntrega::Anulada && $request->user()->can('create', Devolucion::class),
            ],
        ]);
    }

    /**
     * Sirve, en streaming, la imagen de evidencia de un renglón de entrega.
     * Se autoriza contra la ENTREGA dueña del renglón (mismo criterio que el
     * resto del detalle) — nunca por un id enumerable sin revalidar (anti-IDOR).
     */
    public function verEvidencia(Request $request, Evidencia $evidencia): StreamedResponse
    {
        abort_unless($evidencia->evidenciable_type === DetalleEntrega::class, 404);

        $detalle = DetalleEntrega::query()->with('entrega')->find($evidencia->evidenciable_id);
        abort_if($detalle === null || $detalle->entrega === null, 404);

        $this->authorize('view', $detalle->entrega);
        abort_unless(Storage::disk($evidencia->disco)->exists($evidencia->ruta), 404);

        return Storage::disk($evidencia->disco)->response($evidencia->ruta, 'evidencia.'.$evidencia->extension, [
            'Content-Type' => $evidencia->mime,
            'Content-Disposition' => 'inline; filename="evidencia.'.$evidencia->extension.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Sube una identificación oficial faltante al EXPEDIENTE del colaborador
     * durante el flujo de entrega. Autorización de mínimo privilegio (ver
     * `autorizarConsultaIdentidad()`): basta poder registrar entregas y tener
     * al colaborador en el alcance de empresa. NO permite reemplazar una INE
     * ya existente (eso vive en el módulo de expediente y exige
     * `colaboradores.expediente-administrar`).
     */
    public function guardarDocumentoIdentidad(GuardarIdentidadEntregaRequest $request, Colaborador $colaborador, ServicioIdentidadColaborador $identidad): JsonResponse
    {
        $documento = $identidad->guardarFaltante($colaborador, $request->file('archivo'), $request->user(), 'una entrega');

        return response()->json([
            'ok' => true,
            'documento' => [...$identidad->payload($documento), 'url' => route('entregas.documento-identidad.ver', $colaborador)],
        ]);
    }
}
