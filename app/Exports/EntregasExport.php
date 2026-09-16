<?php

namespace App\Exports;

use App\Exports\Concerns\DecoraConContexto;
use App\Models\EntregaUniforme;
use App\Soporte\ContextoExportacion;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * `WithStrictNullComparison`: ver nota en `InventarioExport` — sin ella,
 * una cantidad en `0` se omitiría de la celda en vez de mostrarse.
 */
class EntregasExport implements FromCollection, ShouldAutoSize, WithEvents, WithHeadings, WithStrictNullComparison, WithTitle
{
    use DecoraConContexto;

    /**
     * @param  Collection<int, EntregaUniforme>  $entregas
     */
    public function __construct(
        private readonly Collection $entregas,
        private readonly ContextoExportacion $contexto,
    ) {}

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
                    $entrega->empresa?->nombre_comercial,
                    $entrega->sucursal?->nombre,
                    $entrega->colaborador?->numero_empleado,
                    $entrega->colaborador?->nombre_completo,
                    $entrega->encargado?->name,
                    $entrega->servicio === null ? 'Sin servicio' : $entrega->servicio->contrato->nombre.' — '.$entrega->servicio->nombre,
                    $detalle->activo_nombre_snapshot,
                    $detalle->talla_valor_snapshot ?? 'Sin talla',
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
        return ['Folio', 'Fecha de entrega', 'Empresa', 'Sucursal', 'N.º empleado', 'Colaborador', 'Responsable', 'Servicio', 'Activo', 'Talla', 'Cantidad'];
    }

    public function title(): string
    {
        return 'Datos';
    }

    protected function contextoExportacion(): ContextoExportacion
    {
        return $this->contexto;
    }

    /**
     * No es `count($this->entregas)`: cada entrega se explota en una fila
     * por renglón (ver `collection()`).
     */
    protected function totalFilas(): int
    {
        return (int) $this->entregas->sum(fn (EntregaUniforme $e): int => $e->detalles->count());
    }
}
