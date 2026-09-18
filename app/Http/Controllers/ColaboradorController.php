<?php

namespace App\Http\Controllers;

use App\Acciones\CambiarEmpresaColaborador;
use App\Acciones\CambiarServicioColaborador;
use App\Enums\TipoGrafica;
use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Controllers\Concerns\ExportaListado;
use App\Http\Requests\Colaboradores\ActualizarFotoColaboradorRequest;
use App\Http\Requests\Colaboradores\CambiarEmpresaColaboradorRequest;
use App\Http\Requests\Colaboradores\CambiarServicioColaboradorRequest;
use App\Http\Requests\Colaboradores\GuardarColaboradorRequest;
use App\Models\Area;
use App\Models\Colaborador;
use App\Models\Devolucion;
use App\Models\Empresa;
use App\Models\EntregaUniforme;
use App\Models\Sucursal;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioCustodiaColaborador;
use App\Servicios\ServicioExpediente;
use App\Servicios\ServicioHistoricoColaborador;
use App\Soporte\ContextoExportacion;
use App\Soporte\GeneradorNumeroEmpleado;
use App\Soporte\PaletaGraficas;
use App\Soporte\SerieGraficaReporte;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Colaboradores por empresa. La empresa llega como filtro (listado) o campo
 * `empresa_id` (alta); en edición queda fijada por el registro. Sucursales y
 * áreas del formulario se acotan a la empresa elegida y al alcance del usuario.
 */
class ColaboradorController extends Controller
{
    use ConEmpresa;
    use ExportaListado;

    public function __construct(
        private readonly ServicioAuditoria $auditoria,
        private readonly GeneradorNumeroEmpleado $generadorNumeroEmpleado,
    ) {}

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
            // Mismo permiso que ya exige `desactivar` en el detalle
            // (`ColaboradorPolicy::desactivar`, prop `puedeEliminar` allá
            // también); el listado ya sólo trae colaboradores dentro del
            // alcance del usuario (`consultaColaboradores()`), así que este
            // booleano de página equivale exactamente al Gate por fila.
            'puedeEliminar' => $usuario->can('colaboradores.desactivar'),
        ]);
    }

    /**
     * Excel/PDF del listado, respetando los mismos filtros que `index()`.
     */
    public function exportar(Request $request): BinaryFileResponse|HttpResponse
    {
        $this->authorize('viewAny', Colaborador::class);

        $filtros = $this->filtrosListado($request);
        $empresaFiltro = $this->empresaDelFiltro($request);
        $colaboradores = $this->consultaColaboradores($request, $filtros)->get();

        $filas = $colaboradores->map(fn (Colaborador $c): array => [
            $c->numero_empleado,
            $c->nombre_completo,
            $c->curp,
            $c->puesto,
            $c->area,
            $c->correo,
            $c->empresa?->nombre_comercial,
            $c->sucursal?->nombre,
            $c->activo ? 'Activo' : 'Inactivo',
        ])->all();

        $filtrosHumanos = array_filter([
            'Búsqueda' => $filtros['buscar'] ?? null,
            'Sucursal' => ($filtros['sucursal_id'] ?? null) ? Sucursal::query()->find((int) $filtros['sucursal_id'])?->nombre : null,
            'Área' => ($filtros['area_id'] ?? null) ? Area::query()->find((int) $filtros['area_id'])?->nombre : null,
            'Estado' => match ($filtros['estado'] ?? null) {
                'activos' => 'Activos',
                'inactivos' => 'Eliminados',
                default => null,
            },
        ]);

        $contexto = new ContextoExportacion(
            'Colaboradores',
            $empresaFiltro,
            $filtrosHumanos,
            $colaboradores->count(),
            generadoPor: $request->user()?->name,
            kpis: $this->kpisColaboradores($colaboradores),
            graficas: $this->graficasColaboradores($colaboradores),
        );

        return $this->respuestaExportacion($request->input('formato', 'xlsx'), $filas, [
            'N.º empleado', 'Nombre completo', 'CURP', 'Puesto', 'Área', 'Correo', 'Empresa', 'Sucursal', 'Estado',
        ], $contexto);
    }

    /**
     * @param  Collection<int, Colaborador>  $colaboradores
     * @return array<string, string|int>
     */
    private function kpisColaboradores(Collection $colaboradores): array
    {
        $activos = $colaboradores->filter(fn (Colaborador $c): bool => $c->activo)->count();

        return [
            'Colaboradores' => $colaboradores->count(),
            'Activos' => $activos,
            'Inactivos' => $colaboradores->count() - $activos,
            'Empresas' => $colaboradores->pluck('empresa_id')->unique()->count(),
        ];
    }

    /**
     * @param  Collection<int, Colaborador>  $colaboradores
     * @return array<int, SerieGraficaReporte>
     */
    private function graficasColaboradores(Collection $colaboradores): array
    {
        if ($colaboradores->isEmpty()) {
            return [];
        }

        $porEmpresa = $colaboradores
            ->groupBy(fn (Colaborador $c): string => $c->empresa->nombre_comercial)
            ->map->count()
            ->sortDesc()
            ->take(8);

        $porSucursal = $colaboradores
            ->groupBy(fn (Colaborador $c): string => $c->sucursal->nombre)
            ->map->count()
            ->sortDesc()
            ->take(8);

        $activos = $colaboradores->filter(fn (Colaborador $c): bool => $c->activo)->count();
        $inactivos = $colaboradores->count() - $activos;

        return [
            new SerieGraficaReporte('Colaboradores por empresa', TipoGrafica::Barras, $porEmpresa->keys()->all(), $porEmpresa->values()->all()),
            new SerieGraficaReporte('Colaboradores por sucursal', TipoGrafica::Barras, $porSucursal->keys()->all(), $porSucursal->values()->all()),
            new SerieGraficaReporte(
                'Activos / inactivos',
                TipoGrafica::Dona,
                ['Activos', 'Inactivos'],
                [$activos, $inactivos],
                [PaletaGraficas::booleano(true), PaletaGraficas::booleano(false)],
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function filtrosListado(Request $request): array
    {
        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'sucursal_id' => ['nullable', 'integer'],
            'area_id' => ['nullable', 'integer'],
            'estado' => ['nullable', 'in:activos,inactivos,todos'],
        ]);

        // `validate()` con la regla `integer` sólo comprueba el formato, no
        // castea: un query string SIEMPRE llega como texto ("12"). Estos
        // filtros se devuelven tal cual al frontend en `filtros` (ver
        // `index()`), que los compara por igualdad estricta contra el `id`
        // numérico de la opción ya seleccionada en el combobox — sin este
        // cast, "12" !== 12 se lee como "el servidor eligió otra sucursal" y
        // el selector se limpia solo. Mismo cast que ya aplica
        // `ConEmpresa::empresaDelFiltro()` para `empresa_id`.
        foreach (['sucursal_id', 'area_id'] as $campo) {
            if (isset($filtros[$campo])) {
                $filtros[$campo] = (int) $filtros[$campo];
            }
        }

        return $filtros;
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
                ->orWhere('numero_empleado', 'like', "%{$b}%")
                ->orWhere('curp', 'like', "%{$b}%")))
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
        $rutaFoto = $request->hasFile('foto') ? $this->guardarFotoSegura($request->file('foto'), $empresa->id) : null;

        try {
            // El número de empleado NUNCA lo manda el cliente: se reserva
            // atómicamente aquí, dentro del alta real (la previsualización
            // del formulario no es autoritativa).
            $numeroEmpleado = $this->generadorNumeroEmpleado->generar($empresa, (string) $request->validated('nombre_completo'));

            $colaborador = Colaborador::query()->create([
                ...$request->safe()->except(['activo', 'empresa_id', 'foto', 'eliminar_foto']),
                'empresa_id' => $empresa->id,
                'numero_empleado' => $numeroEmpleado,
                'area' => $this->nombreAreaEspejo($empresa->id, $request->integer('area_id') ?: null, $request->input('area')),
                'foto_ruta' => $rutaFoto,
                'activo' => $request->boolean('activo', true),
            ]);
        } catch (Throwable $e) {
            if ($rutaFoto !== null) {
                Storage::disk('local')->delete($rutaFoto);
            }

            throw $e;
        }

        $this->auditoria->registrar('colaboradores', 'crear', [
            'tipo_entidad' => Colaborador::class, 'entidad_id' => $colaborador->id, 'empresa_id' => $empresa->id,
            'descripcion' => 'Alta de colaborador '.$colaborador->nombre_completo,
            'valores_nuevos' => $colaborador->toArray(),
        ]);

        return to_route('colaboradores.index')->with('toast', [
            'type' => 'success',
            'message' => "Colaborador registrado correctamente. Número de empleado: {$colaborador->numero_empleado}.",
        ]);
    }

    public function edit(Request $request, Colaborador $colaborador): Response
    {
        $this->authorize('update', $colaborador);

        $colaborador->load(['sucursal:id,nombre', 'departamento:id,nombre']);

        return Inertia::render('Colaboradores/Formulario', [
            'colaborador' => [
                ...$colaborador->only(['id', 'empresa_id', 'numero_empleado', 'nombre_completo', 'curp', 'sucursal_id', 'puesto', 'area', 'area_id', 'correo', 'activo']),
                'sucursal' => $colaborador->sucursal === null ? null : ['id' => $colaborador->sucursal->id, 'nombre' => $colaborador->sucursal->nombre],
                'area_actual' => $colaborador->departamento === null ? null : ['id' => $colaborador->departamento->id, 'nombre' => $colaborador->departamento->nombre],
                'foto_url' => $colaborador->foto_ruta !== null ? route('colaboradores.foto', $colaborador) : null,
            ],
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
        ]);
    }

    /**
     * Perfil del colaborador: información general, KPIs rápidos y (si el
     * usuario tiene permiso) el expediente digital embebido como pestaña —
     * misma página, sin navegar a otro módulo.
     */
    public function show(Request $request, Colaborador $colaborador, ServicioExpediente $servicioExpediente, ServicioCustodiaColaborador $servicioCustodia): Response
    {
        $this->authorize('view', $colaborador);

        $colaborador->load(['sucursal:id,nombre', 'departamento:id,nombre', 'empresa:id,nombre_comercial', 'servicioActual.contrato']);

        // Aislamiento histórico: los KPIs sólo cuentan lo que el usuario puede
        // efectivamente abrir. Tras un traslado DASTI→SIESA, un usuario con
        // acceso sólo a SIESA no debe ver — ni siquiera como número — la
        // historia DASTI del colaborador. Para alcance global no cambia nada.
        $idsAutorizadas = $this->idsEmpresasAutorizadas($request);
        $colaborador->loadCount([
            'entregas as entregas_count' => fn ($q) => $q->whereIn('empresa_id', $idsAutorizadas),
            'devoluciones as devoluciones_count' => fn ($q) => $q->whereIn('empresa_id', $idsAutorizadas),
        ]);

        $usuario = $request->user();
        $puedeVerExpediente = $usuario->can('verExpediente', $colaborador);
        $fotoUrl = $colaborador->foto_ruta !== null ? route('colaboradores.foto', $colaborador) : null;

        // Sólo Admin/Superadmin (alcance global) ven el desglose por empresa
        // de origen del histórico — evita que un total agregado (p. ej.
        // "Entregas: 5") se lea como si perteneciera todo a la empresa
        // ACTUAL del colaborador tras un traslado. El total ya visible en
        // `kpis` no cambia; esto sólo lo hace trazable. Restringidos siguen
        // sin ver nada fuera de `$idsAutorizadas` (ya aplicado arriba).
        $historicoPorEmpresa = $usuario->tieneAlcanceGlobal()
            ? $this->historicoPorEmpresa($colaborador, $idsAutorizadas)
            : null;

        return Inertia::render('Colaboradores/Detalle', [
            'colaborador' => [
                ...$colaborador->only(['id', 'empresa_id', 'numero_empleado', 'nombre_completo', 'curp', 'sucursal_id', 'puesto', 'area', 'area_id', 'correo', 'activo']),
                'empresa_nombre' => $colaborador->empresa?->nombre_comercial,
                'sucursal' => $colaborador->sucursal === null ? null : ['id' => $colaborador->sucursal->id, 'nombre' => $colaborador->sucursal->nombre],
                'area_actual' => $colaborador->departamento === null ? null : ['id' => $colaborador->departamento->id, 'nombre' => $colaborador->departamento->nombre],
                'servicio_actual' => $colaborador->servicioActual === null ? null : [
                    'id' => $colaborador->servicioActual->id,
                    'nombre' => $colaborador->servicioActual->nombre,
                    'contrato' => ['id' => $colaborador->servicioActual->contrato->id, 'nombre' => $colaborador->servicioActual->contrato->nombre],
                ],
                'foto_url' => $fotoUrl,
            ],
            'kpis' => [
                'documentos' => $puedeVerExpediente ? $servicioExpediente->contarSlotsVisibles($colaborador, $usuario) : 0,
                'entregas' => $colaborador->entregas_count,
                'devoluciones' => $colaborador->devoluciones_count,
                // Piezas físicas ACTUALMENTE bajo custodia (unidades
                // identificadas asignadas + saldo pendiente de renglones por
                // cantidad, devoluciones parciales incluidas) — fuente única
                // `ServicioCustodiaColaborador`, nunca sólo `UnidadActivo`.
                // Acotado a `$idsAutorizadas` por el mismo aislamiento
                // histórico que el resto de estos KPIs.
                'activos_asignados' => $servicioCustodia->totalPiezasPendientes($colaborador, $idsAutorizadas->all()),
            ],
            'historicoPorEmpresa' => $historicoPorEmpresa,
            'puedeEditar' => $usuario->can('update', $colaborador),
            'puedeEliminar' => $usuario->can('desactivar', $colaborador),
            'puedeCambiarEmpresa' => $usuario->can('cambiarEmpresa', $colaborador),
            'puedeVerHistorico' => $usuario->can('verHistorico', $colaborador),
            'puedeVerExpediente' => $puedeVerExpediente,
            'expediente' => $puedeVerExpediente ? [
                'id' => $colaborador->id,
                'nombre_completo' => $colaborador->nombre_completo,
                'numero_empleado' => $colaborador->numero_empleado,
                'foto_url' => $fotoUrl,
                ...$servicioExpediente->payload($colaborador, $usuario, (string) $request->query('estado', 'activos')),
            ] : null,
        ]);
    }

    /**
     * Histórico laboral completo del colaborador: periodos por empresa, con
     * fechas reales (nunca inventadas), sucursal/área/número de empleado de
     * cada tramo, servicios asociados, y entregas/devoluciones de esa
     * empresa. Sólo alcance global — ver `ColaboradorPolicy::verHistorico`.
     */
    public function historico(Colaborador $colaborador, ServicioHistoricoColaborador $servicio): Response
    {
        $this->authorize('verHistorico', $colaborador);

        $colaborador->loadMissing('empresa:id,nombre_comercial');

        return Inertia::render('Colaboradores/Historico', [
            'colaborador' => [
                'id' => $colaborador->id,
                'nombre_completo' => $colaborador->nombre_completo,
                'numero_empleado' => $colaborador->numero_empleado,
                'empresa_actual' => $colaborador->empresa?->nombre_comercial,
            ],
            'periodos' => $servicio->construir($colaborador),
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
        // `sucursal_id` es un `*_id` y `DescripcionAuditoria` oculta esas
        // claves del diff humano a propósito (evita mostrar un id crudo sin
        // nombre resuelto) — a diferencia de `area` (columna espejo de
        // texto, ya legible tal cual), sucursal no tiene mirror. Se
        // resuelve el nombre ANTES/DESPUÉS aquí mismo (sin tocar el modelo)
        // sólo para que el cambio de sucursal quede trazable en Auditoría/
        // Histórico — ver `ServicioHistoricoColaborador::movimientosInternosDelPeriodo()`.
        $sucursalAnteriorId = $colaborador->sucursal_id;
        $rutaFotoAnterior = $colaborador->foto_ruta;
        $rutaFotoNueva = $request->hasFile('foto') ? $this->guardarFotoSegura($request->file('foto'), $colaborador->empresa_id) : null;
        // Caso C/E: "Quitar" sin reemplazo. Sólo cuenta si no llegó foto nueva.
        $eliminarFoto = $rutaFotoNueva === null && $request->boolean('eliminar_foto') && $rutaFotoAnterior !== null;

        try {
            $colaborador->update([
                ...$request->safe()->except(['activo', 'empresa_id', 'foto', 'eliminar_foto']),
                'area' => $this->nombreAreaEspejo($colaborador->empresa_id, $request->integer('area_id') ?: null, $request->input('area')),
                'foto_ruta' => $rutaFotoNueva ?? ($eliminarFoto ? null : $colaborador->foto_ruta),
                'activo' => $request->boolean('activo', $colaborador->activo),
            ]);
        } catch (Throwable $e) {
            if ($rutaFotoNueva !== null) {
                Storage::disk('local')->delete($rutaFotoNueva);
            }

            throw $e;
        }

        // La foto anterior sólo se borra DESPUÉS de que el cambio quedó
        // guardado en BD con éxito — si algo falla antes, el colaborador
        // conserva su foto original en vez de quedarse sin ninguna. Aplica
        // tanto al reemplazo (Caso B/D) como a la eliminación (Caso C/E).
        if (($rutaFotoNueva !== null || $eliminarFoto) && $rutaFotoAnterior !== null) {
            Storage::disk('local')->delete($rutaFotoAnterior);
        }

        $nuevos = $colaborador->toArray();

        if ($sucursalAnteriorId !== $colaborador->sucursal_id) {
            $anteriores['sucursal'] = Sucursal::query()->find($sucursalAnteriorId)?->nombre;
            $nuevos['sucursal'] = Sucursal::query()->find($colaborador->sucursal_id)?->nombre;
        }

        $this->auditoria->registrar('colaboradores', 'editar', [
            'tipo_entidad' => Colaborador::class, 'entidad_id' => $colaborador->id, 'empresa_id' => $colaborador->empresa_id,
            'descripcion' => 'Edición de colaborador '.$colaborador->nombre_completo.($eliminarFoto ? ' · foto eliminada' : ''),
            'valores_anteriores' => $anteriores,
            'valores_nuevos' => $nuevos,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Colaborador actualizado.']);
    }

    /**
     * Cambia sólo la foto de perfil desde el modal dedicado del perfil, sin
     * pasar por el formulario completo de edición.
     */
    public function actualizarFoto(ActualizarFotoColaboradorRequest $request, Colaborador $colaborador): RedirectResponse
    {
        $rutaAnterior = $colaborador->foto_ruta;
        $rutaNueva = $this->guardarFotoSegura($request->file('foto'), $colaborador->empresa_id);

        try {
            $colaborador->update(['foto_ruta' => $rutaNueva]);
        } catch (Throwable $e) {
            Storage::disk('local')->delete($rutaNueva);

            throw $e;
        }

        if ($rutaAnterior !== null) {
            Storage::disk('local')->delete($rutaAnterior);
        }

        $this->auditoria->registrar('colaboradores', 'foto-actualizar', [
            'tipo_entidad' => Colaborador::class, 'entidad_id' => $colaborador->id, 'empresa_id' => $colaborador->empresa_id,
            'descripcion' => 'Foto de perfil actualizada para '.$colaborador->nombre_completo,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Foto actualizada.']);
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
     * Cambia ÚNICAMENTE la ubicación operativa vigente del colaborador
     * (servicio actual). Acción independiente: no crea entregas ni
     * devoluciones, no toca inventario/almacén/unidades — ver
     * `App\Acciones\CambiarServicioColaborador`.
     */
    public function cambiarServicio(CambiarServicioColaboradorRequest $request, Colaborador $colaborador, CambiarServicioColaborador $accion): RedirectResponse
    {
        $datos = $request->validated();

        $accion->ejecutar($colaborador, $datos['servicio_id'] ?? null, $datos['motivo'] ?? null);

        return back()->with('toast', ['type' => 'success', 'message' => 'Servicio actualizado correctamente.']);
    }

    /**
     * Custodia pendiente del colaborador (unidades identificadas asignadas +
     * artículos por cantidad sin devolver), para la previsualización del
     * wizard de transferencia. Sólo lectura; nunca crea devoluciones.
     */
    public function custodiaPendiente(Request $request, Colaborador $colaborador, ServicioCustodiaColaborador $custodia): JsonResponse
    {
        $this->authorize('cambiarEmpresa', $colaborador);

        $colaborador->loadMissing('empresa:id,nombre_comercial');
        $pendientes = $custodia->pendientes($colaborador);

        return response()->json([
            'empresa_actual' => $colaborador->empresa?->nombre_comercial,
            'tiene_pendientes' => $pendientes !== [],
            'pendientes' => $pendientes,
        ]);
    }

    /**
     * Transfiere al colaborador a otra empresa / razón social conservando el
     * mismo registro. Operación DISTINTA del cambio de servicio: bloquea si
     * hay custodia pendiente, genera número de empleado nuevo y deja el
     * servicio sin asignar — ver `App\Acciones\CambiarEmpresaColaborador`.
     */
    public function cambiarEmpresa(CambiarEmpresaColaboradorRequest $request, Colaborador $colaborador, CambiarEmpresaColaborador $accion): RedirectResponse
    {
        $datos = $request->validated();

        $accion->ejecutar(
            $colaborador,
            (int) $datos['empresa_destino_id'],
            (int) $datos['sucursal_destino_id'],
            isset($datos['area_destino_id']) ? (int) $datos['area_destino_id'] : null,
            (string) $datos['motivo'],
        );

        return to_route('colaboradores.show', $colaborador)
            ->with('toast', ['type' => 'success', 'message' => 'Colaborador transferido a la nueva empresa.']);
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
        // Filtros opcionales para "Servicio → Asignar colaboradores": sólo los
        // que hoy no tienen servicio, o sólo los de un servicio concreto.
        $sinServicio = $request->boolean('sin_servicio');
        $conServicioId = $request->filled('con_servicio_id') ? (int) $request->query('con_servicio_id') : null;

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
            ->when($sinServicio, fn ($q) => $q->whereNull('servicio_actual_id'))
            ->when($conServicioId !== null, fn ($q) => $q->where('servicio_actual_id', $conServicioId))
            ->where('activo', true)
            ->whereHas('empresa', fn ($q) => $q->where('activa', true))
            ->whereHas('sucursal', fn ($q) => $q->where('activa', true))
            ->when($termino !== '', fn ($q) => $q->where(fn ($sub) => $sub
                ->where('nombre_completo', 'like', "%{$termino}%")
                ->orWhere('numero_empleado', 'like', "%{$termino}%")
                ->orWhere('curp', 'like', "%{$termino}%")))
            ->with(['empresa:id,nombre_comercial', 'sucursal:id,nombre', 'servicioActual:id,nombre,contrato_id', 'servicioActual.contrato:id,nombre'])
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
                // Ubicación operativa VIGENTE, para precargar Contrato/Servicio
                // en el paso 1 de Entregas — snapshot histórico independiente.
                'servicio_actual' => $c->servicioActual === null ? null : [
                    'id' => $c->servicioActual->id,
                    'nombre' => $c->servicioActual->nombre,
                    'contrato' => ['id' => $c->servicioActual->contrato->id, 'nombre' => $c->servicioActual->contrato->nombre],
                ],
            ]);

        return response()->json(['colaboradores' => $colaboradores]);
    }

    /**
     * Previsualización NO autoritativa del número de empleado que se
     * asignaría al guardar (iniciales del nombre + siguiente consecutivo
     * aproximado, sin reservarlo). El backend vuelve a calcular y reservar
     * el valor definitivo, atómicamente, dentro de `store()` — si otro
     * usuario se adelanta, el consecutivo real puede diferir de esta vista
     * previa.
     */
    public function siguienteNumeroEmpleado(Request $request): JsonResponse
    {
        $this->authorize('create', Colaborador::class);

        $empresaId = (int) $request->query('empresa_id');
        $nombreCompleto = trim((string) $request->query('nombre_completo', ''));

        if ($empresaId <= 0 || $nombreCompleto === '') {
            return response()->json(['numero_empleado' => null]);
        }

        $empresa = Empresa::query()->find($empresaId);

        if ($empresa === null || ! $request->user()->puedeAccederEmpresa($empresa)) {
            return response()->json(['numero_empleado' => null]);
        }

        return response()->json([
            'numero_empleado' => $this->generadorNumeroEmpleado->previsualizar($empresa, $nombreCompleto),
        ]);
    }

    /**
     * Validación ANTICIPADA (UX) de disponibilidad de CURP — nunca sustituye
     * a `GuardarColaboradorRequest` (`Rule::unique` + el índice único en BD
     * siguen siendo la autoridad final ante una carrera de concurrencia).
     * Respuesta mínima a propósito: sólo `{disponible: bool}`, nunca el
     * nombre/empresa del colaborador dueño de esa CURP (es dato personal).
     * En edición, `colaborador_id` excluye el registro propio para que su
     * CURP actual no se marque como duplicada contra sí misma.
     */
    public function validarCurp(Request $request): JsonResponse
    {
        $colaboradorId = $request->filled('colaborador_id') ? (int) $request->input('colaborador_id') : null;
        $colaborador = $colaboradorId !== null ? Colaborador::query()->find($colaboradorId) : null;

        $this->authorize($colaborador !== null ? 'update' : 'create', $colaborador ?? Colaborador::class);

        // Mismo formato que `GuardarColaboradorRequest` (la autoridad
        // definitiva al guardar): un formato inválido nunca llega a
        // consultar la BD, sólo responde "no lo sé todavía" (null), nunca un
        // 422 — es una comprobación anticipada mientras el usuario escribe.
        $curp = Str::upper(trim((string) $request->input('curp', '')));

        if (strlen($curp) !== 18 || ! preg_match(GuardarColaboradorRequest::REGEX_CURP, $curp)) {
            return response()->json(['disponible' => null]);
        }

        // `withTrashed()`: una CURP de un colaborador eliminado lógicamente
        // sigue reservada (mismo criterio que el índice único de BD, que no
        // excluye `deleted_at`) — nunca debe reportarse como "disponible".
        $existe = Colaborador::withTrashed()
            ->where('curp', $curp)
            ->when($colaborador !== null, fn ($q) => $q->whereKeyNot($colaborador->id))
            ->exists();

        return response()->json(['disponible' => ! $existe]);
    }

    /**
     * Desglosa entregas/devoluciones del colaborador por empresa de ORIGEN
     * (sólo dentro de `$idsAutorizadas`, ya resuelto por el llamador — nunca
     * se llama para un usuario restringido). Sólo incluye empresas donde
     * realmente hay historial, para no listar ceros irrelevantes.
     *
     * @param  Collection<int, int>  $idsAutorizadas
     * @return list<array{empresa_id: int, empresa: string, entregas: int, devoluciones: int}>
     */
    private function historicoPorEmpresa(Colaborador $colaborador, Collection $idsAutorizadas): array
    {
        $entregasPorEmpresa = EntregaUniforme::query()
            ->where('colaborador_id', $colaborador->getKey())
            ->whereIn('empresa_id', $idsAutorizadas)
            ->selectRaw('empresa_id, count(*) as total')
            ->groupBy('empresa_id')
            ->pluck('total', 'empresa_id');

        $devolucionesPorEmpresa = Devolucion::query()
            ->where('colaborador_id', $colaborador->getKey())
            ->whereIn('empresa_id', $idsAutorizadas)
            ->selectRaw('empresa_id, count(*) as total')
            ->groupBy('empresa_id')
            ->pluck('total', 'empresa_id');

        $idsConHistorial = $entregasPorEmpresa->keys()->merge($devolucionesPorEmpresa->keys())->unique();

        if ($idsConHistorial->isEmpty()) {
            return [];
        }

        $nombresEmpresa = Empresa::query()->whereIn('id', $idsConHistorial)->pluck('nombre_comercial', 'id');

        return array_values($idsConHistorial
            ->map(fn ($id): array => [
                'empresa_id' => (int) $id,
                'empresa' => (string) ($nombresEmpresa[$id] ?? '—'),
                'entregas' => (int) ($entregasPorEmpresa[$id] ?? 0),
                'devoluciones' => (int) ($devolucionesPorEmpresa[$id] ?? 0),
            ])
            ->sortByDesc(fn (array $f): int => $f['entregas'] + $f['devoluciones'])
            ->all());
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
     * Guarda una foto nueva en el disco privado y devuelve su ruta. Nunca
     * borra la foto anterior aquí — eso lo decide el llamador sólo después de
     * confirmar que la escritura en BD tuvo éxito (evita quedarse sin foto si
     * algo falla a medio camino).
     */
    private function guardarFotoSegura(UploadedFile $archivo, int $empresaId): string
    {
        $ruta = $archivo->store("colaboradores/{$empresaId}", 'local');

        if ($ruta === false) {
            throw new RuntimeException('No fue posible guardar la foto del colaborador.');
        }

        return $ruta;
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
