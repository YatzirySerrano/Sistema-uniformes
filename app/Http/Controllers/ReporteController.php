<?php

namespace App\Http\Controllers;

use App\Enums\EstadoEntrega;
use App\Exports\EntregasExport;
use App\Exports\InventarioExport;
use App\Http\Controllers\Concerns\ConEmpresa;
use App\Models\Almacen;
use App\Models\Sucursal;
use App\Servicios\ServicioReportes;
use App\Soporte\ContextoExportacion;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ReporteController extends Controller
{
    use ConEmpresa;

    public function __construct(private readonly ServicioReportes $reportes) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('reportes.ver'), 403);

        $filtros = $this->filtros($request);
        $empresaIds = $this->idsEmpresasAutorizadas($request);
        $sucursales = $this->sucursalesVisibles($request);
        $almacenes = $this->almacenesVisibles($request);

        $tab = $request->input('tab', 'entregas');
        $datos = ['tab' => $tab, 'filtros' => $filtros];

        if ($tab === 'inventario') {
            $datos['inventario'] = $this->reportes->consultaInventario($empresaIds, $almacenes, $filtros)
                ->paginate($this->porPagina())->withQueryString()
                ->through(fn ($s): array => [
                    'empresa' => $s->empresa?->nombre_comercial, 'almacen' => $s->almacen?->nombre,
                    'activo' => $s->activo?->nombre, 'talla' => $s->talla?->valor,
                    'cantidad' => $s->cantidad, 'minimo' => $s->minimo, 'bajo_minimo' => $s->estaBajoMinimo(),
                ]);
        } else {
            $datos['totales'] = $this->reportes->totalesEntregas($empresaIds, $sucursales, $filtros);
            $datos['entregas'] = $this->reportes->entregasPaginadas($empresaIds, $sucursales, $filtros, $this->porPagina())
                ->through(fn ($e): array => [
                    'folio' => $e->folio,
                    'fecha_entrega' => $e->fecha_entrega->toDateString(),
                    'empresa' => $e->empresa?->nombre_comercial,
                    'sucursal' => $e->sucursal?->nombre,
                    'colaborador' => $e->colaborador?->nombre_completo,
                    'numero_empleado' => $e->colaborador?->numero_empleado,
                    'encargado' => $e->encargado?->name,
                    'estado_etiqueta' => $e->estado->etiqueta(),
                    'activos' => (int) $e->detalles->sum('cantidad'),
                ]);
        }

        return Inertia::render('Reportes/Index', [
            ...$datos,
            'catalogos' => [
                'empresas' => $this->opcionesEmpresas($request),
                'estados' => collect(EstadoEntrega::cases())->map(fn ($e): array => ['valor' => $e->value, 'etiqueta' => $e->etiqueta()]),
            ],
            'puedeExportar' => $request->user()->can('reportes.exportar'),
        ]);
    }

    public function exportarEntregas(Request $request): BinaryFileResponse|HttpResponse
    {
        abort_unless($request->user()->can('reportes.exportar'), 403);

        $filtros = $this->filtros($request);
        $empresaIds = $this->idsEmpresasAutorizadas($request);
        $sucursales = $this->sucursalesVisibles($request);
        $formato = $request->input('formato', 'xlsx');

        $entregas = $this->reportes->consultaEntregas($empresaIds, $sucursales, $filtros)->get();

        $contexto = new ContextoExportacion(
            'Entregas',
            $this->empresaDelFiltro($request),
            $this->filtrosHumanosEntregas($filtros),
            $entregas->count(),
        );

        if ($formato === 'pdf') {
            $pdf = Pdf::loadView('reportes.entregas', [
                'entregas' => $entregas,
                'filtros' => $filtros,
                'contexto' => $contexto,
            ])->setPaper('letter', 'landscape');

            return response($pdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$contexto->nombreArchivo().'.pdf"',
            ]);
        }

        return Excel::download(new EntregasExport($entregas, $contexto), $contexto->nombreArchivo().'.xlsx');
    }

    public function exportarInventario(Request $request): BinaryFileResponse
    {
        abort_unless($request->user()->can('reportes.exportar'), 403);

        $filtros = $this->filtros($request);
        $empresaIds = $this->idsEmpresasAutorizadas($request);
        $almacenes = $this->almacenesVisibles($request);

        $saldos = $this->reportes->consultaInventario($empresaIds, $almacenes, $filtros)->get();

        $contexto = new ContextoExportacion(
            'Inventario',
            $this->empresaDelFiltro($request),
            $this->filtrosHumanosInventario($filtros),
            $saldos->count(),
        );

        return Excel::download(new InventarioExport($saldos, $contexto), $contexto->nombreArchivo().'.xlsx');
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return array<string, string>
     */
    private function filtrosHumanosEntregas(array $filtros): array
    {
        return array_filter([
            'Sucursal' => ($filtros['sucursal_id'] ?? null) ? Sucursal::query()->find((int) $filtros['sucursal_id'])?->nombre : null,
            'Estado' => ($filtros['estado'] ?? null) ? (EstadoEntrega::tryFrom($filtros['estado'])?->etiqueta() ?? $filtros['estado']) : null,
            'Firmado' => match ($filtros['firmado'] ?? null) {
                'si' => 'Sí',
                'no' => 'No',
                default => null,
            },
            'Desde' => ($filtros['desde'] ?? null) ? Carbon::parse($filtros['desde'])->format('d/m/Y') : null,
            'Hasta' => ($filtros['hasta'] ?? null) ? Carbon::parse($filtros['hasta'])->format('d/m/Y') : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return array<string, string>
     */
    private function filtrosHumanosInventario(array $filtros): array
    {
        return array_filter([
            'Almacén' => ($filtros['almacen_id'] ?? null) ? Almacen::query()->find((int) $filtros['almacen_id'])?->nombre : null,
            'Estado' => filter_var($filtros['solo_bajo_minimo'] ?? false, FILTER_VALIDATE_BOOL) ? 'Sólo bajo mínimo' : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function filtros(Request $request): array
    {
        return $request->validate([
            'empresa_id' => ['nullable', 'integer'],
            'sucursal_id' => ['nullable', 'integer'],
            'almacen_id' => ['nullable', 'integer'],
            'colaborador_id' => ['nullable', 'integer'],
            'encargado_id' => ['nullable', 'integer'],
            'activo_id' => ['nullable', 'integer'],
            'talla_id' => ['nullable', 'integer'],
            'estado' => ['nullable', 'string'],
            'firmado' => ['nullable', 'in:si,no'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
            'solo_bajo_minimo' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @return Collection<int, int>
     */
    private function sucursalesVisibles(Request $request): Collection
    {
        $usuario = $request->user();

        return $this->idsEmpresasAutorizadas($request)
            ->flatMap(fn (int $id): array => $this->acceso()->sucursalesAutorizadas($usuario, $id)->pluck('id')->all())
            ->unique()->values();
    }

    /**
     * @return Collection<int, int>
     */
    private function almacenesVisibles(Request $request): Collection
    {
        $usuario = $request->user();

        return $this->idsEmpresasAutorizadas($request)
            ->flatMap(fn (int $id): array => $this->acceso()->almacenesAutorizados($usuario, $id)->pluck('id')->all())
            ->unique()->values();
    }
}
