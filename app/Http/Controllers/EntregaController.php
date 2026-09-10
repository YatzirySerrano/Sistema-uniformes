<?php

namespace App\Http\Controllers;

use App\Acciones\ConfirmarAcuseRecepcion;
use App\Acciones\RegistrarEntregaFirmada;
use App\Acciones\SubirDocumentoExpediente;
use App\Enums\CategoriaDocumentoExpediente;
use App\Enums\EstadoEntrega;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Requests\Entregas\GuardarEntregaRequest;
use App\Http\Requests\Entregas\GuardarIdentidadEntregaRequest;
use App\Models\Colaborador;
use App\Models\DetalleEntrega;
use App\Models\Devolucion;
use App\Models\DocumentoExpediente;
use App\Models\EntregaUniforme;
use App\Models\Evidencia;
use App\Models\SaldoInventario;
use App\Models\User;
use App\Servicios\ServicioEvidencias;
use App\Servicios\ServicioExpediente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
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

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', EntregaUniforme::class);

        $idsAutorizadas = $this->idsEmpresasAutorizadas($request);

        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'empresa_id' => ['nullable', 'integer'],
            'estado' => ['nullable', 'string'],
            'contrato_id' => ['nullable', 'integer'],
            'servicio_id' => ['nullable', 'integer'],
        ]);

        $empresaFiltro = $this->empresaDelFiltro($request);

        $entregas = EntregaUniforme::query()
            ->whereIn('empresa_id', $idsAutorizadas)
            ->when($empresaFiltro !== null, fn ($q) => $q->where('empresa_id', $empresaFiltro->id))
            ->when($filtros['buscar'] ?? null, fn ($q, $b) => $q->where(fn ($s) => $s
                ->where('folio', 'like', "%{$b}%")
                ->orWhereHas('colaborador', fn ($c) => $c->where('nombre_completo', 'like', "%{$b}%")->orWhere('numero_empleado', 'like', "%{$b}%"))))
            ->when($filtros['estado'] ?? null, fn ($q, $e) => $q->where('estado', $e))
            // Servicio es el snapshot histórico de la propia entrega
            // (`entregas_uniformes.servicio_id`); Contrato filtra por el
            // contrato de ese mismo servicio — ninguno de los dos usa el
            // servicio VIGENTE del colaborador, que puede ya haber cambiado.
            ->when($filtros['servicio_id'] ?? null, fn ($q, $s) => $q->where('servicio_id', $s))
            ->when($filtros['contrato_id'] ?? null, fn ($q, $c) => $q->whereHas('servicio', fn ($sq) => $sq->where('contrato_id', $c)))
            ->with(['colaborador:id,nombre_completo,numero_empleado', 'sucursal:id,nombre', 'empresa:id,nombre_comercial', 'encargado:id,name', 'servicio:id,nombre,contrato_id', 'servicio.contrato:id,nombre'])
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
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
            'estados' => collect(EstadoEntrega::cases())->map(fn ($e): array => ['valor' => $e->value, 'etiqueta' => $e->etiqueta()]),
            'puedeCrear' => $request->user()->can('create', EntregaUniforme::class),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', EntregaUniforme::class);

        return Inertia::render('Entregas/Crear', [
            // El flujo único termina SIEMPRE en la firma dentro de la misma
            // pantalla; el encargado que firma es el usuario autenticado.
            'encargado' => [
                'name' => $request->user()->name,
                'email' => $request->user()->email,
            ],
            'textoConsentimiento' => ConfirmarAcuseRecepcion::TEXTO_CONSENTIMIENTO,
        ]);
    }

    /**
     * Existencias por cantidad (empresa+almacén, sin variante desglosada) para
     * mostrar un aviso de disponibilidad en el formulario. El backend siempre
     * revalida con bloqueo al registrar; esto es sólo UX.
     */
    public function disponibilidad(Request $request): JsonResponse
    {
        $this->authorize('create', EntregaUniforme::class);

        $datos = $request->validate([
            'empresa_id' => ['required', 'integer'],
            'almacen_id' => ['required', 'integer'],
        ]);

        if (! $request->user()->puedeAccederEmpresa((int) $datos['empresa_id'])) {
            return response()->json(['saldos' => []]);
        }

        $saldos = SaldoInventario::query()
            ->where('empresa_id', $datos['empresa_id'])
            ->where('almacen_id', $datos['almacen_id'])
            ->get(['activo_id', 'talla_id', 'cantidad'])
            ->map(fn ($s): array => ['activo_id' => $s->activo_id, 'talla_id' => $s->talla_id, 'disponible' => (int) $s->cantidad]);

        return response()->json(['saldos' => $saldos]);
    }

    /**
     * Búsqueda de entregas para originar una devolución (`Devoluciones/Crear`
     * sin `?entrega_id=` precargado). Devuelve entregas no anuladas de las
     * empresas autorizadas; el detalle de renglones pendientes se resuelve al
     * cargar la entrega concreta.
     */
    public function buscar(Request $request): JsonResponse
    {
        $this->authorize('create', Devolucion::class);

        $idsAutorizadas = $this->idsEmpresasAutorizadas($request);
        $termino = trim((string) $request->query('q', ''));

        $entregas = EntregaUniforme::query()
            ->whereIn('empresa_id', $idsAutorizadas)
            ->where('estado', '!=', EstadoEntrega::Anulada)
            ->when($termino !== '', fn ($q) => $q->where(fn ($s) => $s
                ->where('folio', 'like', "%{$termino}%")
                ->orWhereHas('colaborador', fn ($c) => $c->where('nombre_completo', 'like', "%{$termino}%")->orWhere('numero_empleado', 'like', "%{$termino}%"))))
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
                (int) $datos['almacen_id'],
                $request->user()->id,
                $datos['fecha_entrega'],
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
                $datos['firma'],
                $datos['firma_operador'],
                true, // aceptación (validada por la regla `accepted`)
                $request->ip(),
                $request->userAgent(),
                $evidencias,
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
    public function documentoIdentidad(Request $request, Colaborador $colaborador): JsonResponse
    {
        $this->autorizarConsultaIdentidad($request->user(), $colaborador);

        $documento = $this->documentoDeIdentidad($colaborador);
        $version = $documento?->versionActual;

        if ($documento === null || $version === null) {
            return response()->json(['disponible' => false]);
        }

        return response()->json([
            'disponible' => true,
            'nombre' => $documento->nombre,
            'mime' => $version->mime,
            'previsualizable' => ServicioExpediente::esPrevisualizable($version->mime),
            'actualizado_en' => $version->created_at?->toIso8601String(),
            'url' => route('entregas.documento-identidad.ver', $colaborador),
        ]);
    }

    /**
     * Sirve, en streaming y sólo tras validar la autorización, la ÚLTIMA
     * versión del documento de identidad del colaborador. Nunca expone la
     * ruta en disco ni genera una URL pública/predecible; se consulta
     * inline únicamente para la verificación visual del encargado.
     */
    public function verDocumentoIdentidad(Request $request, Colaborador $colaborador): StreamedResponse
    {
        $this->autorizarConsultaIdentidad($request->user(), $colaborador);

        $documento = $this->documentoDeIdentidad($colaborador);
        abort_if($documento === null, 404, 'No hay un documento de identidad en el expediente de este colaborador.');

        $version = $documento->versionActual;
        abort_if($version === null, 404, 'No hay un documento de identidad en el expediente de este colaborador.');
        abort_unless(ServicioExpediente::esPrevisualizable($version->mime), 415, 'El documento de identidad no puede previsualizarse; consúltalo desde el expediente del colaborador.');
        abort_unless(Storage::disk('local')->exists($version->ruta), 404);

        return Storage::disk('local')->response($version->ruta, 'identificacion.'.$version->extension, [
            'Content-Type' => $version->mime,
            'Content-Disposition' => 'inline; filename="identificacion.'.$version->extension.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * El documento de identidad = el documento ACTIVO más reciente del
     * colaborador en la categoría `Identificacion` del expediente (INE /
     * identificación oficial / credencial de elector). Nada hardcodeado por
     * id: se usa el catálogo `CategoriaDocumentoExpediente` y el propio
     * historial de versiones (`versionActual` = la de número más alto).
     */
    private function documentoDeIdentidad(Colaborador $colaborador): ?DocumentoExpediente
    {
        return $colaborador->documentosExpediente()
            ->where('categoria', CategoriaDocumentoExpediente::Identificacion)
            ->where('activo', true)
            ->with('versionActual')
            ->latest('id')
            ->first();
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
    public function guardarDocumentoIdentidad(GuardarIdentidadEntregaRequest $request, Colaborador $colaborador, SubirDocumentoExpediente $accion): JsonResponse
    {
        if ($this->documentoDeIdentidad($colaborador) !== null) {
            throw new ExcepcionDeNegocioSimple('Este colaborador ya tiene una identificación en su expediente. El reemplazo se hace desde su expediente.');
        }

        $accion->ejecutar(
            $colaborador,
            CategoriaDocumentoExpediente::Identificacion,
            'Identificación oficial',
            'Capturada durante una entrega',
            $request->file('archivo'),
            $request->user(),
        );

        // Verificación de persistencia real: `ok:true` sólo si el documento, su
        // versión 1 y el archivo físico existen tras el commit. Un fallo
        // parcial NO se reporta como éxito.
        $documento = $this->documentoDeIdentidad($colaborador->fresh() ?? $colaborador);
        $version = $documento?->versionActual;

        if ($documento === null || $version === null || ! Storage::disk('local')->exists($version->ruta)) {
            Log::error('INE durante entrega: el documento no persistió correctamente', [
                'colaborador_id' => $colaborador->id,
                'documento_id' => $documento?->id,
                'tiene_version' => $version !== null,
            ]);

            throw new ExcepcionDeNegocioSimple('No se pudo guardar la identificación. Inténtalo de nuevo.');
        }

        return response()->json([
            'ok' => true,
            'documento' => [
                'disponible' => true,
                'nombre' => $documento->nombre,
                'mime' => $version->mime,
                'previsualizable' => ServicioExpediente::esPrevisualizable($version->mime),
                'actualizado_en' => $version->created_at?->toIso8601String(),
                'url' => route('entregas.documento-identidad.ver', $colaborador),
            ],
        ]);
    }
}
