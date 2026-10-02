<?php

namespace App\Http\Controllers;

use App\Enums\SeccionDashboard;
use App\Http\Controllers\Concerns\ConEmpresa;
use App\Models\Almacen;
use App\Models\Sucursal;
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

        $usuario = $request->user();

        // Bloques del Dashboard que el usuario puede consultar, resueltos
        // contra sus permisos EFECTIVOS (Spatie) en cada petición — nunca por
        // nombre de rol. El servicio sólo consulta y devuelve esos bloques.
        $secciones = SeccionDashboard::autorizadasPara($usuario);

        // Un filtro sólo tiene sentido si algún bloque visible depende de él,
        // y su combobox sólo puede ofrecerse si el usuario puede usar el
        // buscador de ese catálogo (`sucursales/buscar` / `almacenes/buscar`
        // exigen `viewAny`). Si no está disponible, el parámetro se ignora.
        $filtrosDisponibles = [
            'sucursal' => $usuario->can('viewAny', Sucursal::class)
                && collect($secciones)->contains(fn (SeccionDashboard $s): bool => $s->usaSucursal()),
            'almacen' => $usuario->can('viewAny', Almacen::class)
                && collect($secciones)->contains(fn (SeccionDashboard $s): bool => $s->usaAlmacen()),
            'fechas' => collect($secciones)->contains(fn (SeccionDashboard $s): bool => $s->usaRangoFechas()),
        ];

        // Sin "empresa activa": el filtro de empresa es opcional. Sin él, el
        // Dashboard agrega TODAS las empresas autorizadas del usuario — nunca
        // "la primera" ni obliga a elegir una para poder ver algo.
        $empresaFiltrada = $this->empresaDelFiltro($request);
        $idsAutorizados = $this->idsEmpresasAutorizadas($request);
        $empresaIds = $empresaFiltrada !== null ? [$empresaFiltrada->id] : $idsAutorizados->all();

        [$desde, $hasta] = $this->resolverRango($datos['desde'] ?? null, $datos['hasta'] ?? null);

        $sucursal = null;
        if ($filtrosDisponibles['sucursal'] && ($datos['sucursal_id'] ?? null) !== null) {
            $sucursal = $empresaFiltrada !== null
                ? $this->acceso()->sucursalesAutorizadas($usuario, $empresaFiltrada)->firstWhere('id', $datos['sucursal_id'])
                : $this->acceso()->sucursalesAutorizadasGlobal($usuario)->firstWhere('id', $datos['sucursal_id']);
        }

        $almacen = null;
        if ($filtrosDisponibles['almacen'] && ($datos['almacen_id'] ?? null) !== null) {
            $almacen = $empresaFiltrada !== null
                ? $this->acceso()->almacenesAutorizados($usuario, $empresaFiltrada)->firstWhere('id', $datos['almacen_id'])
                : $this->acceso()->almacenesAutorizadosGlobal($usuario)->firstWhere('id', $datos['almacen_id']);
        }

        // Sucursales que el usuario puede ver (restringido: sólo las suyas),
        // calculadas sólo si alguna sección visible depende de ellas.
        $sucursalesAlcance = collect($secciones)->contains(fn (SeccionDashboard $s): bool => $s->usaAlcanceSucursales())
            ? $this->acceso()->sucursalesAutorizadasGlobal($usuario)->pluck('id')->map(fn ($id): int => (int) $id)->values()->all()
            : [];

        return Inertia::render('Panel', [
            'resumen' => $dashboard->resumen($secciones, $empresaIds, $desde, $hasta, $sucursal?->id, $almacen?->id, $sucursalesAlcance),
            'filtros' => [
                'empresa_id' => $empresaFiltrada?->id,
                'sucursal_id' => $sucursal?->id,
                'almacen_id' => $almacen?->id,
                'desde' => $desde->toDateString(),
                'hasta' => $hasta->toDateString(),
            ],
            'filtrosDisponibles' => $filtrosDisponibles,
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
