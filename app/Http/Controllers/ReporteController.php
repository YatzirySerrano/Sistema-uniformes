<?php

namespace App\Http\Controllers;

use App\Enums\EstadoStockInventario;
use App\Enums\EstadoVisibleUnidad;
use App\Enums\TipoGrafica;
use App\Exports\EntregasExport;
use App\Exports\InventarioExport;
use App\Http\Controllers\Concerns\ConEmpresa;
use App\Models\Almacen;
use App\Models\SaldoInventario;
use App\Models\Sucursal;
use App\Servicios\ServicioReportes;
use App\Soporte\ContextoExportacion;
use App\Soporte\PaletaGraficas;
use App\Soporte\SerieGraficaReporte;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\LaravelPdf\Enums\Format;
use Spatie\LaravelPdf\Facades\Pdf;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * KPIs y gráficas de Entregas/Inventario se calculan UNA sola vez por
 * petición (`ServicioReportes::metricas*()`) y alimentan por igual la
 * pantalla, el Excel y el PDF — nunca un cálculo distinto por formato, para
 * que los tres siempre muestren los mismos números.
 *
 * La firma es obligatoria en el flujo actual de Entregas: este reporte ya no
 * expone el filtro/columna/gráfica de "Estado" (pendiente de firma dejó de
 * ser información relevante). Esto es exclusivo de Reportes — no cambia el
 * enum `EstadoEntrega` ni la lógica operativa de `EntregaController`.
 *
 * @phpstan-import-type FilaTopActivo from ServicioReportes
 * @phpstan-import-type FilaPorSucursal from ServicioReportes
 */
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
            $metricasInventario = $this->reportes->metricasInventario($empresaIds, $almacenes, $filtros);
            $metricasUnidades = $this->reportes->metricasUnidades($empresaIds, $almacenes, $filtros);

            $datos['kpis'] = $this->kpisInventario($metricasInventario, $metricasUnidades);
            $datos['graficas'] = [
                'unidades_por_estado' => $this->serieUnidadesPorEstado($metricasUnidades),
                'por_almacen' => $metricasInventario['por_almacen']->values()->all(),
                'riesgo_desabasto' => $metricasInventario['riesgo_desabasto']->values()->all(),
            ];
            $datos['inventario'] = $this->reportes->consultaInventario($empresaIds, $almacenes, $filtros)
                ->paginate($this->porPagina())->withQueryString()
                ->through(fn (SaldoInventario $s): array => [
                    'empresa' => $s->empresa?->nombre_comercial,
                    'almacen' => $s->almacen?->nombre,
                    'activo' => $s->activo?->nombre,
                    'talla' => $s->talla?->valor,
                    'disponible' => (int) $s->cantidad,
                    'minimo' => (int) $s->minimo,
                    'estado_stock' => EstadoStockInventario::paraSaldo($s)->value,
                    'estado_stock_etiqueta' => EstadoStockInventario::paraSaldo($s)->etiqueta(),
                ]);
        } else {
            $metricas = $this->reportes->metricasEntregas($empresaIds, $sucursales, $filtros);
            $comparativa = $this->reportes->serieEntregasVsDevoluciones($empresaIds, $sucursales, $filtros);

            $datos['kpis'] = $this->kpisEntregas($metricas);
            $datos['graficas'] = [
                'entregas_vs_devoluciones' => $comparativa['periodos'] === [] ? null : $comparativa,
                'top_activos' => $metricas['top_activos'],
                'por_sucursal' => $metricas['por_sucursal'],
            ];
            $datos['entregas'] = $this->reportes->entregasPaginadas($empresaIds, $sucursales, $filtros, $this->porPagina())
                ->through(fn ($e): array => [
                    'folio' => $e->folio,
                    'fecha_entrega' => $e->fecha_entrega->toDateString(),
                    'empresa' => $e->empresa?->nombre_comercial,
                    'sucursal' => $e->sucursal?->nombre,
                    'colaborador' => $e->colaborador?->nombre_completo,
                    'numero_empleado' => $e->colaborador?->numero_empleado,
                    'encargado' => $e->encargado?->name,
                    'piezas' => (int) $e->detalles->sum('cantidad'),
                ]);
        }

        return Inertia::render('Reportes/Index', [
            ...$datos,
            'catalogos' => [
                'empresas' => $this->opcionesEmpresas($request),
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
        $metricas = $this->reportes->metricasEntregas($empresaIds, $sucursales, $filtros);
        $comparativa = $this->reportes->serieEntregasVsDevoluciones($empresaIds, $sucursales, $filtros);

        $contexto = new ContextoExportacion(
            'Entregas',
            $this->empresaDelFiltro($request),
            $this->filtrosHumanosEntregas($filtros),
            $metricas['entregas'],
            generadoPor: $request->user()?->name,
            kpis: $this->kpisEntregas($metricas),
            graficas: $this->graficasEntregas($metricas, $comparativa),
            kpiDescripciones: $this->descripcionesKpisEntregas(),
        );

        if ($formato === 'pdf') {
            $pdf = Pdf::view('reportes.entregas', [
                'entregas' => $entregas,
                'filtros' => $filtros,
                'contexto' => $contexto,
            ])
                ->format(Format::Letter)
                ->landscape()
                ->margins(10, 10, 16, 10)
                ->footerView('reportes._pie');

            if ($contexto->graficas !== []) {
                $pdf->waitUntilReady();
            }

            return response($pdf->generatePdfContent(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$contexto->nombreArchivo().'.pdf"',
            ]);
        }

        return Excel::download(new EntregasExport($entregas, $contexto), $contexto->nombreArchivo().'.xlsx');
    }

    /**
     * Nombres pensados para que el usuario final entienda cada número sin
     * adivinar (ver `.ai/rules` — nunca un tecnicismo interno tipo
     * "renglón" sin contexto). Misma fuente para pantalla, Excel y PDF.
     *
     * "Registros de artículos" = `$metricas['renglones']`: cuenta FILAS de
     * `detalles_entrega` (un registro por combinación entrega+activo+
     * variante), NUNCA piezas — un registro puede agrupar varias piezas.
     * Deliberadamente distinto de "Piezas entregadas" (`$metricas['piezas']`,
     * la suma de cantidades). Ver `descripcionesKpisEntregas()` para el texto
     * exacto que evita esa confusión en Excel/PDF/pantalla.
     *
     * @param  array{entregas: int, renglones: int, piezas: int, colaboradores: int, tipos_activos: int, top_activos: array<int, FilaTopActivo>, por_sucursal: array<int, FilaPorSucursal>}  $metricas
     * @return array<string, int>
     */
    private function kpisEntregas(array $metricas): array
    {
        return [
            'Entregas realizadas' => $metricas['entregas'],
            'Registros de artículos' => $metricas['renglones'],
            'Piezas entregadas' => $metricas['piezas'],
            'Colaboradores con entrega' => $metricas['colaboradores'],
            'Tipos de activos entregados' => $metricas['tipos_activos'],
        ];
    }

    /**
     * Descripción breve por KPI — fuente ÚNICA para la tarjeta del Excel
     * (`ContextoExportacion::kpiDescripciones` → `kpisResueltos()`), el
     * glosario del PDF (`reportes/_glosario-kpis.blade.php`, que lee
     * `$contexto->kpiDescripciones` directamente) y `TarjetaKpi` en pantalla
     * (`DESCRIPCION_KPI` en `Reportes/Index.vue` — mismo texto, runtime
     * distinto). Las claves deben calzar exactamente con `kpisEntregas()`.
     *
     * @return array<string, string>
     */
    private function descripcionesKpisEntregas(): array
    {
        return [
            'Entregas realizadas' => 'Número de entregas registradas en el periodo filtrado.',
            'Registros de artículos' => 'Renglones de artículos incluidos en las entregas; un registro puede contener varias piezas.',
            'Piezas entregadas' => 'Suma total de piezas entregadas en el periodo filtrado.',
            'Colaboradores con entrega' => 'Colaboradores distintos que recibieron al menos una entrega.',
            'Tipos de activos entregados' => 'Activos distintos incluidos en las entregas del periodo.',
        ];
    }

    /**
     * Máximo 3 gráficas: comparativa de piezas entregadas/devueltas por
     * periodo, top de activos (con variante cuando aplica) por piezas, y
     * distribución por sucursal — esta última se muestra siempre que haya
     * al menos una sucursal con entregas reales en el filtro actual (nunca
     * se omite sólo porque el resultado quede en una sola sucursal, p. ej.
     * una empresa que opera desde un único punto).
     *
     * @param  array{top_activos: array<int, FilaTopActivo>, por_sucursal: array<int, FilaPorSucursal>}  $metricas
     * @param  array{granularidad: 'diaria'|'mensual', periodos: array<int, string>, entregadas: array<int, int>, devueltas: array<int, int>}  $comparativa
     * @return array<int, SerieGraficaReporte>
     */
    private function graficasEntregas(array $metricas, array $comparativa): array
    {
        $graficas = [];

        if ($comparativa['periodos'] !== []) {
            $graficas[] = new SerieGraficaReporte(
                titulo: 'Entregas vs devoluciones (piezas)',
                tipo: TipoGrafica::Barras,
                etiquetas: $this->etiquetasPeriodo($comparativa['periodos'], $comparativa['granularidad']),
                valores: $comparativa['entregadas'],
                colores: [PaletaGraficas::principal(), PaletaGraficas::serie(1)],
                etiquetaSerie: 'Piezas entregadas',
                valoresComparacion: $comparativa['devueltas'],
                etiquetaComparacion: 'Piezas devueltas',
            );
        }

        if ($metricas['top_activos'] !== []) {
            $graficas[] = new SerieGraficaReporte(
                titulo: 'Top activos entregados (piezas)',
                tipo: TipoGrafica::Barras,
                etiquetas: array_map(fn (array $r): string => $r['talla'] ? "{$r['activo']} ({$r['talla']})" : $r['activo'], $metricas['top_activos']),
                valores: array_column($metricas['top_activos'], 'piezas'),
                // Nombre + variante: nunca cortar la variante a medias.
                etiquetasLargasEnDosLineas: true,
            );
        }

        if ($metricas['por_sucursal'] !== []) {
            $graficas[] = new SerieGraficaReporte(
                'Piezas entregadas por sucursal',
                TipoGrafica::Barras,
                array_column($metricas['por_sucursal'], 'sucursal'),
                array_column($metricas['por_sucursal'], 'piezas'),
            );
        }

        return $graficas;
    }

    /**
     * @param  array<int, string>  $periodos  Fechas `Y-m-d` (diaria) o `Y-m` (mensual).
     * @return array<int, string>
     */
    private function etiquetasPeriodo(array $periodos, string $granularidad): array
    {
        return array_map(
            fn (string $p): string => $granularidad === 'mensual'
                ? Carbon::createFromFormat('Y-m', $p)->translatedFormat('M Y')
                : Carbon::parse($p)->translatedFormat('d M'),
            $periodos,
        );
    }

    public function exportarInventario(Request $request): BinaryFileResponse|HttpResponse
    {
        abort_unless($request->user()->can('reportes.exportar'), 403);

        $filtros = $this->filtros($request);
        $empresaIds = $this->idsEmpresasAutorizadas($request);
        $almacenes = $this->almacenesVisibles($request);
        $formato = $request->input('formato', 'xlsx');

        // Misma consulta filtrada que el `index()` (paginada) y que el Excel —
        // el scope por empresa/almacén ya viaja dentro, no se reconstruye.
        $saldos = $this->reportes->consultaInventario($empresaIds, $almacenes, $filtros)->get();
        $metricasInventario = $this->reportes->metricasInventario($empresaIds, $almacenes, $filtros);
        $metricasUnidades = $this->reportes->metricasUnidades($empresaIds, $almacenes, $filtros);

        $contexto = new ContextoExportacion(
            'Inventario',
            $this->empresaDelFiltro($request),
            $this->filtrosHumanosInventario($filtros),
            $saldos->count(),
            generadoPor: $request->user()?->name,
            kpis: $this->kpisInventario($metricasInventario, $metricasUnidades),
            graficas: $this->graficasInventario($metricasInventario, $metricasUnidades),
            kpiDescripciones: $this->descripcionesKpisInventario(),
        );

        if ($formato === 'pdf') {
            $pdf = Pdf::view('reportes.inventario', [
                'saldos' => $saldos,
                'contexto' => $contexto,
            ])
                ->format(Format::Letter)
                ->landscape()
                ->margins(10, 10, 16, 10)
                ->footerView('reportes._pie');

            if ($contexto->graficas !== []) {
                $pdf->waitUntilReady();
            }

            return response($pdf->generatePdfContent(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$contexto->nombreArchivo().'.pdf"',
            ]);
        }

        return Excel::download(new InventarioExport($saldos, $contexto), $contexto->nombreArchivo().'.xlsx');
    }

    /**
     * Sólo 5 KPIs — el resumen ejecutivo, no el desglose completo (ese vive en
     * la gráfica "Unidades por estado", que sigue mostrando reparación/
     * perdidas/robadas/baja sin cambios). "Renglón" se traduce a "variante/
     * talla" para que quede claro que se cuentan combinaciones de activo +
     * variante, no filas de una tabla técnica. Misma fuente para pantalla,
     * Excel y PDF — los tres muestran exactamente estos 5.
     *
     * @param  array{piezas_disponibles: int, renglones_bajo_minimo: int, renglones_sin_existencias: int}  $metricasInventario
     * @param  array<string, int>  $metricasUnidades  clave = `EstadoVisibleUnidad::value`
     * @return array<string, int>
     */
    private function kpisInventario(array $metricasInventario, array $metricasUnidades): array
    {
        return [
            'Piezas disponibles' => $metricasInventario['piezas_disponibles'],
            'Variantes/tallas bajo mínimo' => $metricasInventario['renglones_bajo_minimo'],
            'Variantes/tallas sin existencias' => $metricasInventario['renglones_sin_existencias'],
            'Unidades disponibles' => $metricasUnidades[EstadoVisibleUnidad::Disponible->value],
            'Unidades asignadas' => $metricasUnidades[EstadoVisibleUnidad::Asignado->value],
        ];
    }

    /**
     * Descripción breve por KPI — misma fuente única que
     * `descripcionesKpisEntregas()` (Excel/PDF/pantalla). Las claves deben
     * calzar exactamente con `kpisInventario()`.
     *
     * "Variantes/tallas..." es la etiqueta, pero la DIMENSIÓN real no
     * siempre es una talla: `saldos_inventario` se llavea por
     * `empresa + almacén + activo + talla`, y `talla_id` es NULLABLE
     * ("sin variante" cuando el activo no usa tallas). Por eso la
     * descripción habla de "posiciones de inventario" en vez de asumir que
     * siempre hay una talla — sería una descripción falsa para un activo sin
     * variantes.
     *
     * @return array<string, string>
     */
    private function descripcionesKpisInventario(): array
    {
        return [
            'Piezas disponibles' => 'Suma de existencias disponibles en el alcance filtrado.',
            'Variantes/tallas bajo mínimo' => 'Posiciones de inventario (empresa + almacén + activo + variante, cuando el activo la usa) cuya existencia disponible llegó a su mínimo configurado o está por debajo.',
            'Variantes/tallas sin existencias' => 'Posiciones de inventario (empresa + almacén + activo + variante, cuando el activo la usa) sin ninguna pieza disponible.',
            'Unidades disponibles' => 'Unidades de seguimiento individual listas para entregar.',
            'Unidades asignadas' => 'Unidades de seguimiento individual actualmente entregadas a un colaborador.',
        ];
    }

    /**
     * Máximo 3 gráficas: unidades por estado (dona), disponibilidad por
     * almacén y riesgo de desabasto (top por mayor faltante) — nunca una
     * gráfica de "sí/no bajo mínimo" que no dice cuánto falta.
     *
     * @param  array{por_almacen: Collection<int, array{almacen: string, piezas: int}>, riesgo_desabasto: Collection<int, array{activo: string, talla: mixed, disponible: int, minimo: int, faltante: int}>}  $metricasInventario
     * @param  array<string, int>  $metricasUnidades
     * @return array<int, SerieGraficaReporte>
     */
    private function graficasInventario(array $metricasInventario, array $metricasUnidades): array
    {
        $graficas = [];

        if (array_sum($metricasUnidades) > 0) {
            $graficas[] = new SerieGraficaReporte(
                'Unidades por estado',
                TipoGrafica::Dona,
                collect(EstadoVisibleUnidad::cases())->map(fn (EstadoVisibleUnidad $e): string => $e->etiqueta())->all(),
                collect(EstadoVisibleUnidad::cases())->map(fn (EstadoVisibleUnidad $e): int => $metricasUnidades[$e->value])->all(),
                collect(EstadoVisibleUnidad::cases())->map(fn (EstadoVisibleUnidad $e): string => PaletaGraficas::estadoUnidad($e))->all(),
            );
        }

        if ($metricasInventario['por_almacen']->isNotEmpty()) {
            $graficas[] = new SerieGraficaReporte(
                'Disponible por almacén',
                TipoGrafica::Barras,
                $metricasInventario['por_almacen']->pluck('almacen')->all(),
                $metricasInventario['por_almacen']->pluck('piezas')->all(),
            );
        }

        if ($metricasInventario['riesgo_desabasto']->isNotEmpty()) {
            $graficas[] = new SerieGraficaReporte(
                titulo: 'Riesgo de desabasto (faltante)',
                tipo: TipoGrafica::Barras,
                etiquetas: $metricasInventario['riesgo_desabasto']->map(fn (array $r): string => $r['talla'] ? "{$r['activo']} ({$r['talla']})" : $r['activo'])->all(),
                valores: $metricasInventario['riesgo_desabasto']->pluck('faltante')->all(),
                colores: [PaletaGraficas::alerta()],
                // Nombre + variante: nunca cortar la variante a medias.
                etiquetasLargasEnDosLineas: true,
            );
        }

        return $graficas;
    }

    /**
     * @param  array<string, int>  $metricasUnidades
     * @return array<int, array{estado: string, etiqueta: string, total: int}>
     */
    private function serieUnidadesPorEstado(array $metricasUnidades): array
    {
        return collect(EstadoVisibleUnidad::cases())
            ->map(fn (EstadoVisibleUnidad $e): array => [
                'estado' => $e->value,
                'etiqueta' => $e->etiqueta(),
                'total' => $metricasUnidades[$e->value],
            ])->all();
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return array<string, string>
     */
    private function filtrosHumanosEntregas(array $filtros): array
    {
        return array_filter([
            'Sucursal' => ($filtros['sucursal_id'] ?? null) ? Sucursal::query()->find((int) $filtros['sucursal_id'])?->nombre : null,
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
