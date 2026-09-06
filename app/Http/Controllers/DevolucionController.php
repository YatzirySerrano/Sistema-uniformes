<?php

namespace App\Http\Controllers;

use App\Acciones\RegistrarDevolucion;
use App\Enums\CondicionDevolucion;
use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoUnidadActivo;
use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Controllers\Concerns\ExportaListado;
use App\Http\Requests\Devoluciones\GuardarDevolucionRequest;
use App\Models\DetalleDevolucion;
use App\Models\Devolucion;
use App\Models\EntregaUniforme;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Devoluciones de activos. SIEMPRE se originan desde una entrega concreta
 * (`?entrega_id=` precarga el formulario con sus renglones pendientes); el
 * almacén destino se elige explícitamente (por defecto el de origen de la
 * entrega, seleccionable si abastece la empresa y está activo).
 */
class DevolucionController extends Controller
{
    use ConEmpresa;
    use ExportaListado;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Devolucion::class);

        $empresaFiltro = $this->empresaDelFiltro($request);

        $devoluciones = $this->consultaDevoluciones($request)
            ->paginate($this->porPagina())
            ->withQueryString()
            ->through(fn (Devolucion $d): array => [
                'id' => $d->id,
                'folio' => $d->folio,
                'empresa' => $d->empresa?->nombre_comercial,
                'colaborador' => $d->colaborador?->nombre_completo,
                'entrega_folio' => $d->entrega?->folio,
                'sucursal' => $d->sucursal?->nombre,
                'registrada_por' => $d->registradaPor?->name,
                'fecha' => $d->fecha->toDateString(),
                'renglones' => $d->detalles_count,
            ]);

        return Inertia::render('Devoluciones/Index', [
            'devoluciones' => $devoluciones,
            'filtros' => ['empresa_id' => $empresaFiltro?->id],
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
            'puedeCrear' => $request->user()->can('create', Devolucion::class),
        ]);
    }

    /**
     * Excel/PDF del listado, respetando el mismo filtro que `index()`.
     */
    public function exportar(Request $request): BinaryFileResponse|HttpResponse
    {
        $this->authorize('viewAny', Devolucion::class);

        $devoluciones = $this->consultaDevoluciones($request)->get();

        $filas = $devoluciones->map(fn (Devolucion $d): array => [
            $d->folio,
            $d->empresa?->nombre_comercial,
            $d->colaborador?->nombre_completo,
            $d->entrega?->folio,
            $d->sucursal?->nombre,
            $d->registradaPor?->name,
            $d->fecha->format('d/m/Y'),
            (int) $d->detalles_count,
        ])->all();

        return $this->respuestaExportacion($request->input('formato', 'xlsx'), $filas, [
            'Folio', 'Empresa', 'Colaborador', 'Entrega', 'Sucursal', 'Registró', 'Fecha', 'Renglones',
        ], 'Devoluciones');
    }

    /**
     * @return Builder<Devolucion>
     */
    private function consultaDevoluciones(Request $request): Builder
    {
        $idsAutorizadas = $this->idsEmpresasAutorizadas($request);
        $empresaFiltro = $this->empresaDelFiltro($request);

        return Devolucion::query()
            ->whereIn('empresa_id', $idsAutorizadas)
            ->when($empresaFiltro !== null, fn (Builder $q) => $q->where('empresa_id', $empresaFiltro->id))
            ->with(['colaborador:id,nombre_completo,numero_empleado', 'sucursal:id,nombre', 'empresa:id,nombre_comercial', 'entrega:id,folio', 'registradaPor:id,name'])
            ->withCount('detalles')
            ->latest();
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Devolucion::class);

        $entrega = null;
        $entregaId = $request->integer('entrega_id');

        if ($entregaId > 0) {
            $candidata = EntregaUniforme::query()->with(['colaborador:id,nombre_completo,numero_empleado', 'empresa:id,nombre_comercial', 'almacen:id,nombre', 'detalles.talla:id,valor', 'detalles.unidadActivo:id,codigo,estado,condicion'])->find($entregaId);

            if ($candidata !== null && $request->user()->puedeAccederEmpresa($candidata->empresa_id)) {
                $entrega = $candidata;
            }
        }

        return Inertia::render('Devoluciones/Crear', [
            'entrega' => $entrega === null ? null : $this->presentarEntrega($entrega),
            'condiciones' => collect(CondicionDevolucion::cases())->map(fn ($c): array => ['valor' => $c->value, 'etiqueta' => $c->etiqueta()]),
            'condicionesUnidad' => collect(CondicionUnidadActivo::cases())->filter(fn ($c) => ! $c->esIncidencia())->values()
                ->map(fn ($c): array => ['valor' => $c->value, 'etiqueta' => $c->etiqueta()]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentarEntrega(EntregaUniforme $entrega): array
    {
        return [
            'id' => $entrega->id,
            'folio' => $entrega->folio,
            'empresa_id' => $entrega->empresa_id,
            'empresa' => $entrega->empresa?->nombre_comercial,
            'colaborador' => $entrega->colaborador?->nombre_completo,
            'almacen_id' => $entrega->almacen_id,
            'almacen' => $entrega->almacen?->nombre,
            'renglones' => $entrega->detalles->map(function ($d): array {
                $yaDevuelto = (int) DetalleDevolucion::query()->where('detalle_entrega_id', $d->id)->sum('cantidad');

                return [
                    'detalle_entrega_id' => $d->id,
                    'activo' => $d->activo_nombre_snapshot,
                    'talla' => $d->talla_valor_snapshot,
                    'cantidad' => $d->cantidad,
                    'pendiente' => $d->unidad_activo_id === null ? max($d->cantidad - $yaDevuelto, 0) : null,
                    'es_unidad' => $d->unidad_activo_id !== null,
                    'unidad_codigo' => $d->unidadActivo?->codigo,
                    'unidad_disponible' => $d->unidadActivo?->estado === EstadoUnidadActivo::Asignada,
                    'unidad_estado_visible' => $d->unidadActivo?->estadoVisible()->value,
                    'unidad_estado_visible_etiqueta' => $d->unidadActivo?->estadoVisible()->etiqueta(),
                ];
            }),
        ];
    }

    public function store(GuardarDevolucionRequest $request, RegistrarDevolucion $accion): RedirectResponse
    {
        $datos = $request->validated();

        $entrega = EntregaUniforme::findOrFail((int) $datos['entrega_uniforme_id']);
        abort_unless($request->user()->puedeAccederEmpresa($entrega->empresa_id), 403, 'No tienes acceso a la empresa de esa entrega.');

        $devolucion = $accion->ejecutar(
            (int) $datos['entrega_uniforme_id'],
            (int) $datos['almacen_id'],
            $datos['fecha'],
            $datos['activos'] ?? [],
            $datos['unidades'] ?? [],
            $request->user()->id,
            $datos['motivo'] ?? null,
            $datos['notas'] ?? null,
        );

        return to_route('devoluciones.index')->with('toast', [
            'type' => 'success', 'message' => "Devolución {$devolucion->folio} registrada.",
        ]);
    }
}
