<?php

namespace App\Exports;

use App\Exports\Concerns\DecoraConContexto;
use App\Models\SaldoInventario;
use App\Soporte\ContextoExportacion;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class InventarioExport implements FromArray, ShouldAutoSize, WithEvents, WithHeadings, WithTitle
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
                (string) $saldo->talla?->valor,
                (int) $saldo->cantidad,
                (int) $saldo->minimo,
                $saldo->estaBajoMinimo() ? 'Sí' : 'No',
            ];
        }

        return $filas;
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Empresa', 'Almacén', 'Activo', 'Talla', 'Existencia', 'Mínimo', 'Bajo mínimo'];
    }

    public function title(): string
    {
        return 'Inventario';
    }

    protected function contextoExportacion(): ContextoExportacion
    {
        return $this->contexto;
    }

    protected function totalFilas(): int
    {
        return $this->saldos->count();
    }
}
