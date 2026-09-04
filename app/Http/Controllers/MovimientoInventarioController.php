<?php

namespace App\Http\Controllers;

use App\Enums\TipoMovimiento;
use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Controllers\Concerns\ExportaListado;
use App\Models\MovimientoInventario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Historial de movimientos de inventario por empresa. Filtros por empresa y
 * almacén (búsqueda). `sucursal_id` sólo aparece como procedencia histórica.
 */
class MovimientoInventarioController extends Controller
{
    use ConEmpresa;
    use ExportaListado;

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('inventario.ver'), 403);

        $usuario = $request->user();
        $idsScope = $this->idsScope($request);
        $empresaFiltro = $this->empresaDelFiltro($request);
        $filtros = $this->filtrosListado($request);

        $movimientos = $this->consultaMovimientos($request, $filtros)
            ->paginate($this->porPagina())
            ->withQueryString()
            ->through(fn (MovimientoInventario $m): array => [
                'id' => $m->id,
                'empresa' => $m->empresa?->nombre_comercial,
                'tipo' => $m->tipo->value,
                'tipo_etiqueta' => $m->tipo->etiqueta(),
                'direccion' => $m->direccion->value,
                'cantidad' => $m->cantidad,
                'existencia_anterior' => $m->existencia_anterior,
                'existencia_resultante' => $m->existencia_resultante,
                'almacen' => $m->almacen?->nombre,
                'sucursal' => $m->sucursal?->nombre,
                'activo' => $m->activo?->nombre,
                'talla' => $m->talla?->valor,
                'motivo' => $m->motivo,
                'realizado_por' => $m->realizadoPor?->name,
                'ocurrido_en' => $m->ocurrido_en->toIso8601String(),
            ]);

        return Inertia::render('Inventario/Movimientos', [
            'movimientos' => $movimientos,
            'filtros' => [...$filtros, 'empresa_id' => $empresaFiltro?->id],
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
            'almacenes' => $idsScope
                ->flatMap(fn (int $id): array => $this->acceso()->almacenesAutorizados($usuario, $id)->all())
                ->unique('id')->map->only(['id', 'nombre'])->values(),
            'tipos' => collect(TipoMovimiento::cases())->map(fn ($t): array => ['valor' => $t->value, 'etiqueta' => $t->etiqueta()]),
        ]);
    }

    /**
     * Excel/PDF del listado, respetando los mismos filtros que `index()`.
     */
    public function exportar(Request $request): BinaryFileResponse|HttpResponse
    {
        abort_unless($request->user()->can('inventario.ver'), 403);

        $filtros = $this->filtrosListado($request);
        $movimientos = $this->consultaMovimientos($request, $filtros)->get();

        $filas = $movimientos->map(fn (MovimientoInventario $m): array => [
            $m->ocurrido_en->format('d/m/Y H:i'),
            $m->empresa?->nombre_comercial,
            $m->tipo->etiqueta(),
            $m->direccion->value,
            $m->cantidad,
            $m->existencia_anterior,
            $m->existencia_resultante,
            $m->almacen?->nombre,
            $m->sucursal?->nombre,
            $m->activo?->nombre,
            $m->talla?->valor,
            $m->motivo,
            $m->realizadoPor?->name,
        ])->all();

        return $this->respuestaExportacion($request->input('formato', 'xlsx'), $filas, [
            'Fecha', 'Empresa', 'Tipo', 'Dirección', 'Cantidad', 'Existencia anterior', 'Existencia resultante',
            'Almacén', 'Sucursal', 'Activo', 'Talla', 'Motivo', 'Realizó',
        ], 'Movimientos de inventario');
    }

    /**
     * @return Collection<int, int>
     */
    private function idsScope(Request $request): Collection
    {
        $idsAutorizadas = $this->idsEmpresasAutorizadas($request);
        $empresaFiltro = $this->empresaDelFiltro($request);

        return $empresaFiltro !== null ? collect([$empresaFiltro->id]) : $idsAutorizadas;
    }

    /**
     * @return array<string, mixed>
     */
    private function filtrosListado(Request $request): array
    {
        return $request->validate([
            'almacen_id' => ['nullable', 'integer'],
            'activo_id' => ['nullable', 'integer'],
            'tipo' => ['nullable', 'string'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Builder<MovimientoInventario>
     */
    private function consultaMovimientos(Request $request, array $filtros): Builder
    {
        $usuario = $request->user();
        $idsScope = $this->idsScope($request);

        $almacenesVisibles = $idsScope
            ->flatMap(fn (int $id): array => $this->acceso()->almacenesAutorizados($usuario, $id)->pluck('id')->all())
            ->unique()->values();

        return MovimientoInventario::query()
            ->whereIn('empresa_id', $idsScope)
            ->where(function (Builder $q) use ($almacenesVisibles): void {
                $q->whereIn('almacen_id', $almacenesVisibles)->orWhereNull('almacen_id');
            })
            ->when($filtros['almacen_id'] ?? null, fn (Builder $q, $v) => $q->where('almacen_id', $v))
            ->when($filtros['activo_id'] ?? null, fn (Builder $q, $v) => $q->where('activo_id', $v))
            ->when($filtros['tipo'] ?? null, fn (Builder $q, $t) => $q->where('tipo', $t))
            ->when($filtros['desde'] ?? null, fn (Builder $q, $d) => $q->whereDate('ocurrido_en', '>=', $d))
            ->when($filtros['hasta'] ?? null, fn (Builder $q, $h) => $q->whereDate('ocurrido_en', '<=', $h))
            ->with(['empresa:id,nombre_comercial', 'almacen:id,nombre', 'sucursal:id,nombre', 'activo:id,nombre', 'talla:id,valor', 'realizadoPor:id,name'])
            ->latest('ocurrido_en');
    }
}
