<?php

namespace App\Http\Controllers;

use App\Enums\EstadoEntrega;
use App\Exports\EntregasExport;
use App\Exports\InventarioExport;
use App\Http\Controllers\Concerns\ConEmpresaActiva;
use App\Servicios\ServicioReportes;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ReporteController extends Controller
{
    use ConEmpresaActiva;

    public function __construct(private readonly ServicioReportes $reportes) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('reportes.ver'), 403);
        $empresa = $this->empresaActiva();
        $filtros = $this->filtros($request);
        $sucursales = $this->contexto()->sucursalesDisponibles()->pluck('id');

        $tab = $request->input('tab', 'entregas');

        $datos = ['tab' => $tab, 'filtros' => $filtros];

        if ($tab === 'inventario') {
            $datos['inventario'] = $this->reportes->consultaInventario($empresa->id, $sucursales, $filtros)
                ->paginate($this->porPagina())->withQueryString()
                ->through(fn ($s): array => [
                    'sucursal' => $s->sucursal?->nombre, 'prenda' => $s->prenda?->nombre, 'talla' => $s->talla?->valor,
                    'cantidad' => $s->cantidad, 'minimo' => $s->minimo, 'bajo_minimo' => $s->estaBajoMinimo(),
                ]);
        } else {
            $datos['totales'] = $this->reportes->totalesEntregas($empresa->id, $sucursales, $filtros);
            $datos['entregas'] = $this->reportes->entregasPaginadas($empresa->id, $sucursales, $filtros, $this->porPagina())
                ->through(fn ($e): array => [
                    'folio' => $e->folio,
                    'fecha_entrega' => $e->fecha_entrega->toDateString(),
                    'sucursal' => $e->sucursal?->nombre,
                    'colaborador' => $e->colaborador?->nombre_completo,
                    'numero_empleado' => $e->colaborador?->numero_empleado,
                    'encargado' => $e->encargado?->name,
                    'estado_etiqueta' => $e->estado->etiqueta(),
                    'prendas' => (int) $e->detalles->sum('cantidad'),
                ]);
        }

        return Inertia::render('Reportes/Index', [
            ...$datos,
            'catalogos' => [
                'sucursales' => $this->contexto()->sucursalesDisponibles()->map->only(['id', 'nombre'])->values(),
                'prendas' => $empresa->prendas()->orderBy('nombre')->get(['id', 'nombre']),
                'tallas' => $empresa->tallas()->ordenadas()->get(['id', 'valor']),
                'estados' => collect(EstadoEntrega::cases())->map(fn ($e): array => ['valor' => $e->value, 'etiqueta' => $e->etiqueta()]),
            ],
            'puedeExportar' => $request->user()->can('reportes.exportar'),
        ]);
    }

    public function exportarEntregas(Request $request): BinaryFileResponse|HttpResponse
    {
        abort_unless($request->user()->can('reportes.exportar'), 403);
        $empresa = $this->empresaActiva();
        $filtros = $this->filtros($request);
        $sucursales = $this->contexto()->sucursalesDisponibles()->pluck('id');
        $formato = $request->input('formato', 'xlsx');

        $entregas = $this->reportes->consultaEntregas($empresa->id, $sucursales, $filtros)->get();

        if ($formato === 'pdf') {
            $pdf = Pdf::loadView('reportes.entregas', [
                'empresa' => $empresa,
                'entregas' => $entregas,
                'filtros' => $filtros,
            ])->setPaper('letter', 'landscape');

            return response($pdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="reporte-entregas-'.$empresa->codigo.'-'.now()->toDateString().'.pdf"',
            ]);
        }

        return Excel::download(new EntregasExport($entregas), 'reporte-entregas-'.$empresa->codigo.'-'.now()->toDateString().'.xlsx');
    }

    public function exportarInventario(Request $request): BinaryFileResponse
    {
        abort_unless($request->user()->can('reportes.exportar'), 403);
        $empresa = $this->empresaActiva();
        $filtros = $this->filtros($request);
        $sucursales = $this->contexto()->sucursalesDisponibles()->pluck('id');

        $saldos = $this->reportes->consultaInventario($empresa->id, $sucursales, $filtros)->get();

        return Excel::download(new InventarioExport($saldos), 'reporte-inventario-'.$empresa->codigo.'-'.now()->toDateString().'.xlsx');
    }

    /**
     * @return array<string, mixed>
     */
    private function filtros(Request $request): array
    {
        return $request->validate([
            'sucursal_id' => ['nullable', 'integer'],
            'colaborador_id' => ['nullable', 'integer'],
            'encargado_id' => ['nullable', 'integer'],
            'prenda_id' => ['nullable', 'integer'],
            'talla_id' => ['nullable', 'integer'],
            'estado' => ['nullable', 'string'],
            'firmado' => ['nullable', 'in:si,no'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
            'solo_bajo_minimo' => ['nullable', 'boolean'],
        ]);
    }
}
