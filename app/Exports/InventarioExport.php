<?php

namespace App\Exports;

use App\Models\SaldoInventario;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class InventarioExport implements FromArray, WithHeadings, WithTitle
{
    /**
     * @param  Collection<int, SaldoInventario>  $saldos
     */
    public function __construct(private readonly Collection $saldos) {}

    /**
     * @return array<int, array<int, string|int>>
     */
    public function array(): array
    {
        $filas = [];

        foreach ($this->saldos as $saldo) {
            $filas[] = [
                (string) $saldo->sucursal?->nombre,
                (string) $saldo->prenda?->nombre,
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
        return ['Sucursal', 'Prenda', 'Talla', 'Existencia', 'Mínimo', 'Bajo mínimo'];
    }

    public function title(): string
    {
        return 'Inventario';
    }
}
