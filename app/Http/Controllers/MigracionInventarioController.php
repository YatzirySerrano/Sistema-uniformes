<?php

namespace App\Http\Controllers;

use App\Acciones\MigrarSaldosLegacyAAlmacen;
use App\Http\Controllers\Concerns\ConEmpresaActiva;
use App\Models\Almacen;
use App\Models\SaldoInventario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Asistente de migración de existencias legacy (inventario por sucursal →
 * inventario por almacén). Lista las sucursales con saldos pendientes y permite
 * asignarlos por lote a un almacén de la misma empresa.
 */
class MigracionInventarioController extends Controller
{
    use ConEmpresaActiva;

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('inventario.migrar'), 403);
        $empresa = $this->empresaActiva();

        $pendientes = SaldoInventario::query()
            ->where('empresa_id', $empresa->id)
            ->pendienteMigracion()
            ->with(['sucursal:id,nombre,codigo', 'activo:id,nombre', 'talla:id,valor'])
            ->get()
            ->groupBy('sucursal_id')
            ->map(function (Collection $saldos): array {
                /** @var SaldoInventario|null $primero */
                $primero = $saldos->first();
                $sucursal = $primero?->sucursal;

                return [
                    'sucursal_id' => $sucursal?->id,
                    'sucursal' => $sucursal?->nombre,
                    'codigo' => $sucursal?->codigo,
                    'filas' => $saldos->count(),
                    'unidades' => (int) $saldos->sum('cantidad'),
                    'detalle' => $saldos->map(fn (SaldoInventario $s): array => [
                        'activo' => $s->activo?->nombre,
                        'talla' => $s->talla?->valor,
                        'cantidad' => (int) $s->cantidad,
                        'minimo' => (int) $s->minimo,
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();

        $almacenes = Almacen::query()
            ->where('empresa_id', $empresa->id)
            ->where('activo', true)
            ->with('sucursales:id')
            ->orderBy('nombre')
            ->get()
            ->map(fn (Almacen $a): array => [
                'id' => $a->id,
                'nombre' => $a->nombre,
                'codigo' => $a->codigo,
                'sucursales_abastecidas' => $a->sucursales->pluck('id'),
            ]);

        return Inertia::render('Inventario/MigracionLegacy', [
            'pendientes' => $pendientes,
            'almacenes' => $almacenes,
        ]);
    }

    public function resolver(Request $request, MigrarSaldosLegacyAAlmacen $accion): RedirectResponse
    {
        abort_unless($request->user()->can('inventario.migrar'), 403);
        $empresa = $this->empresaActiva();

        $datos = $request->validate([
            'sucursal_id' => ['required', 'integer'],
            'almacen_id' => ['required', 'integer'],
        ]);

        $migrados = $accion->ejecutar(
            $empresa->id,
            (int) $datos['sucursal_id'],
            (int) $datos['almacen_id'],
            $request->user()->id,
        );

        return back()->with('toast', [
            'type' => $migrados > 0 ? 'success' : 'info',
            'message' => $migrados > 0
                ? "{$migrados} saldo(s) trasladados al almacén."
                : 'No había saldos pendientes para esa sucursal.',
        ]);
    }
}
