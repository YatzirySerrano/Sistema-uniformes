<?php

namespace App\Exports;

use App\Enums\EstadoStockInventario;
use App\Exports\Concerns\DecoraConContexto;
use App\Models\SaldoInventario;
use App\Soporte\ContextoExportacion;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * `WithStrictNullComparison`: sin ella, `Worksheet::fromArray()` compara con
 * `!=` en vez de `!==` (`Maatwebsite\Excel\Sheet::append()`), y en PHP
 * `0 == null` — cualquier celda con `0` (existencia real de "sin
 * existencias") se omite por completo y queda vacía en vez de mostrar "0".
 */
class InventarioExport implements FromArray, ShouldAutoSize, WithEvents, WithHeadings, WithStrictNullComparison, WithTitle
{
    use DecoraConContexto;

    /**
     * @param  Collection<int, SaldoInventario>  $saldos
     */
    public function __construct(
        private readonly Collection $saldos,
        private readonly ContextoExportacion $contexto,
    ) {}

    /**
     * @return array<int, array<int, string|int>>
     */
    public function array(): array
    {
        $filas = [];

        foreach ($this->saldos as $saldo) {
            $filas[] = [
                (string) $saldo->empresa?->nombre_comercial,
                (string) $saldo->almacen?->nombre,
                (string) $saldo->activo?->nombre,
                $this->tallaTexto($saldo),
                // Nunca vacío: 0 significa "sin existencias", un valor
                // desconocido nunca debería llegar aquí (columna NOT NULL).
                (int) $saldo->cantidad,
                (int) $saldo->minimo,
                EstadoStockInventario::paraSaldo($saldo)->etiqueta(),
            ];
        }

        return $filas;
    }

    /**
     * "Sin variante" cuando el activo no usa tallas — nunca una celda vacía
     * ni un guion ambiguo.
     */
    private function tallaTexto(SaldoInventario $saldo): string
    {
        $talla = $saldo->talla;

        return $talla === null ? 'Sin talla' : $talla->valor;
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Empresa', 'Almacén', 'Activo', 'Talla', 'Disponible', 'Mínimo', 'Estado de stock'];
    }

    public function title(): string
    {
        return 'Datos';
    }

    protected function contextoExportacion(): ContextoExportacion
    {
        return $this->contexto;
    }

    protected function totalFilas(): int
    {
        return $this->saldos->count();
    }

    /**
     * Reportes conserva su diseño visual actual — el rediseño "ejecutivo"
     * de `DecoraConContexto` es sólo para los listados administrativos
     * genéricos (`ListadoExport`).
     */
    protected function estiloAdministrativo(): bool
    {
        return false;
    }
}
