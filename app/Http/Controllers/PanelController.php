<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConEmpresa;
use App\Servicios\ServicioDashboard;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class PanelController extends Controller
{
    use ConEmpresa;

    /**
     * Ventana máxima del rango de fechas (evita agrupar por día un histórico
     * enorme si alguien fuerza el query string).
     */
    private const MAX_DIAS_RANGO = 180;

    private const DIAS_POR_DEFECTO = 29;

    public function index(Request $request, ServicioDashboard $dashboard): Response
    {
        $datos = $request->validate([
            'empresa_id' => ['nullable', 'integer'],
            'sucursal_id' => ['nullable', 'integer'],
            'almacen_id' => ['nullable', 'integer'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
        ]);

        // Sin "empresa activa": el filtro de empresa es opcional. Sin él, el
        // Dashboard agrega TODAS las empresas autorizadas del usuario — nunca
        // "la primera" ni obliga a elegir una para poder ver algo.
        $empresaFiltrada = $this->empresaDelFiltro($request);
        $idsAutorizados = $this->idsEmpresasAutorizadas($request);
        $empresaIds = $empresaFiltrada !== null ? [$empresaFiltrada->id] : $idsAutorizados->all();

        [$desde, $hasta] = $this->resolverRango($datos['desde'] ?? null, $datos['hasta'] ?? null);

        $sucursal = $empresaFiltrada !== null
            ? $this->acceso()->sucursalesAutorizadas($request->user(), $empresaFiltrada)->firstWhere('id', $datos['sucursal_id'] ?? null)
            : $this->acceso()->sucursalesAutorizadasGlobal($request->user())->firstWhere('id', $datos['sucursal_id'] ?? null);

        $almacen = $empresaFiltrada !== null
            ? $this->acceso()->almacenesAutorizados($request->user(), $empresaFiltrada)->firstWhere('id', $datos['almacen_id'] ?? null)
            : $this->acceso()->almacenesAutorizadosGlobal($request->user())->firstWhere('id', $datos['almacen_id'] ?? null);

        return Inertia::render('Panel', [
            'resumen' => $dashboard->resumen($empresaIds, $desde, $hasta, $sucursal?->id, $almacen?->id),
            'filtros' => [
                'empresa_id' => $empresaFiltrada?->id,
                'sucursal_id' => $sucursal?->id,
                'almacen_id' => $almacen?->id,
                'desde' => $desde->toDateString(),
                'hasta' => $hasta->toDateString(),
            ],
            'sucursalSeleccionada' => $sucursal === null ? null : ['id' => $sucursal->id, 'nombre' => $sucursal->nombre],
            'almacenSeleccionado' => $almacen === null ? null : ['id' => $almacen->id, 'nombre' => $almacen->nombre],
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
            'totalEmpresasIncluidas' => count($empresaIds),
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolverRango(?string $desde, ?string $hasta): array
    {
        $hoy = Carbon::today();

        $fechaHasta = $hasta !== null ? Carbon::parse($hasta)->startOfDay() : $hoy->copy();
        $fechaDesde = $desde !== null ? Carbon::parse($desde)->startOfDay() : $fechaHasta->copy()->subDays(self::DIAS_POR_DEFECTO);

        if ($fechaDesde->gt($fechaHasta)) {
            [$fechaDesde, $fechaHasta] = [$fechaHasta, $fechaDesde];
        }

        if ($fechaDesde->diffInDays($fechaHasta) > self::MAX_DIAS_RANGO) {
            $fechaDesde = $fechaHasta->copy()->subDays(self::MAX_DIAS_RANGO);
        }

        return [$fechaDesde, $fechaHasta];
    }
}
