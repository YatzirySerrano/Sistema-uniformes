<?php

namespace App\Exports;

use App\Models\EntregaUniforme;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class EntregasExport implements FromCollection, WithHeadings, WithTitle
{
    /**
     * @param  Collection<int, EntregaUniforme>  $entregas
     */
    public function __construct(private readonly Collection $entregas) {}

    /**
     * @return Collection<int, array<int, string|int>>
     */
    public function collection(): Collection
    {
        $filas = collect();

        foreach ($this->entregas as $entrega) {
            foreach ($entrega->detalles as $detalle) {
                $filas->push([
                    $entrega->folio,
                    $entrega->fecha_entrega->format('d/m/Y'),
                    $entrega->sucursal?->nombre,
                    $entrega->colaborador?->numero_empleado,
                    $entrega->colaborador?->nombre_completo,
                    $entrega->encargado?->name,
                    $entrega->estado->etiqueta(),
                    $detalle->activo_nombre_snapshot,
                    $detalle->talla_valor_snapshot,
                    $detalle->cantidad,
                ]);
            }
        }

        return $filas;
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Folio', 'Fecha de entrega', 'Sucursal', 'N.º empleado', 'Colaborador', 'Responsable', 'Estado', 'Activo', 'Talla', 'Cantidad'];
    }

    public function title(): string
    {
        return 'Entregas';
    }
}
