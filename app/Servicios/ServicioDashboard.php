<?php

namespace App\Servicios;

use App\Enums\EstadoEntrega;
use App\Models\Colaborador;
use App\Models\DetalleEntrega;
use App\Models\Empresa;
use App\Models\EntregaUniforme;
use App\Models\MovimientoInventario;
use App\Models\SaldoInventario;
use App\Models\Sucursal;
use Illuminate\Support\Carbon;

/**
 * Métricas del panel para la empresa activa.
 */
class ServicioDashboard
{
    /**
     * @return array<string, mixed>
     */
    public function resumen(Empresa $empresa): array
    {
        $inicioMes = Carbon::now()->startOfMonth();

        $entregasMes = EntregaUniforme::query()
            ->where('empresa_id', $empresa->id)
            ->where('fecha_entrega', '>=', $inicioMes)
            ->count();

        $pendientesFirma = EntregaUniforme::query()
            ->where('empresa_id', $empresa->id)
            ->where('estado', EstadoEntrega::PendienteFirma->value)
            ->count();

        $prendasEntregadasMes = (int) DetalleEntrega::query()
            ->whereHas('entrega', fn ($q) => $q->where('empresa_id', $empresa->id)->where('fecha_entrega', '>=', $inicioMes))
            ->sum('cantidad');

        $stockBajo = SaldoInventario::query()
            ->where('empresa_id', $empresa->id)
            ->bajoMinimo()
            ->count();

        return [
            'colaboradores_activos' => Colaborador::query()->where('empresa_id', $empresa->id)->where('activo', true)->count(),
            'entregas_mes' => $entregasMes,
            'pendientes_firma' => $pendientesFirma,
            'prendas_entregadas_mes' => $prendasEntregadasMes,
            'stock_bajo' => $stockBajo,
            'entregas_recientes' => EntregaUniforme::query()
                ->where('empresa_id', $empresa->id)
                ->with(['colaborador:id,nombre_completo,numero_empleado', 'sucursal:id,nombre'])
                ->latest()
                ->limit(8)
                ->get()
                ->map(fn (EntregaUniforme $e): array => [
                    'id' => $e->id,
                    'folio' => $e->folio,
                    'colaborador' => $e->colaborador?->nombre_completo,
                    'sucursal' => $e->sucursal?->nombre,
                    'estado' => $e->estado->value,
                    'estado_etiqueta' => $e->estado->etiqueta(),
                    'fecha_entrega' => $e->fecha_entrega->toDateString(),
                ])->all(),
            'movimientos_recientes' => MovimientoInventario::query()
                ->where('empresa_id', $empresa->id)
                ->with(['prenda:id,nombre', 'talla:id,valor', 'sucursal:id,nombre'])
                ->latest('ocurrido_en')
                ->limit(8)
                ->get()
                ->map(fn (MovimientoInventario $m): array => [
                    'id' => $m->id,
                    'tipo' => $m->tipo->value,
                    'tipo_etiqueta' => $m->tipo->etiqueta(),
                    'direccion' => $m->direccion->value,
                    'cantidad' => $m->cantidad,
                    'prenda' => $m->prenda?->nombre,
                    'talla' => $m->talla?->valor,
                    'sucursal' => $m->sucursal?->nombre,
                    'existencia_resultante' => $m->existencia_resultante,
                    'ocurrido_en' => $m->ocurrido_en->toIso8601String(),
                ])->all(),
            'stock_bajo_detalle' => SaldoInventario::query()
                ->where('empresa_id', $empresa->id)
                ->bajoMinimo()
                ->with(['prenda:id,nombre', 'talla:id,valor', 'sucursal:id,nombre'])
                ->limit(10)
                ->get()
                ->map(fn (SaldoInventario $s): array => [
                    'prenda' => $s->prenda?->nombre,
                    'talla' => $s->talla?->valor,
                    'sucursal' => $s->sucursal?->nombre,
                    'cantidad' => $s->cantidad,
                    'minimo' => $s->minimo,
                ])->all(),
            'distribucion_sucursal' => $this->distribucionPorSucursal($empresa, $inicioMes),
        ];
    }

    /**
     * @return array<int, array{sucursal: string, total: int}>
     */
    private function distribucionPorSucursal(Empresa $empresa, Carbon $desde): array
    {
        $totales = EntregaUniforme::query()
            ->where('empresa_id', $empresa->id)
            ->where('fecha_entrega', '>=', $desde)
            ->selectRaw('sucursal_id, count(*) as total')
            ->groupBy('sucursal_id')
            ->pluck('total', 'sucursal_id');

        $nombres = Sucursal::query()
            ->whereIn('id', $totales->keys()->all())
            ->pluck('nombre', 'id');

        return $totales
            ->map(fn ($total, $id): array => [
                'sucursal' => (string) ($nombres[$id] ?? 's/d'),
                'total' => (int) $total,
            ])
            ->values()
            ->all();
    }
}
