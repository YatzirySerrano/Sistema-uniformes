<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Export genérico de un listado ya resuelto a filas planas (mismas filas que
 * ve la pantalla y el PDF: una sola consulta por módulo, tres salidas). Los
 * exports más específicos (`EntregasExport`, `InventarioExport`) siguen
 * existiendo para reportes con forma propia; éste cubre el resto de módulos
 * (Empresas, Sucursales, Áreas, Almacenes, Conjuntos, Movimientos,
 * Devoluciones, Unidades, Colaboradores, Auditoría) sin repetir la misma
 * clase boilerplate una y otra vez.
 */
class ListadoExport implements FromArray, WithHeadings, WithTitle
{
    /**
     * @param  array<int, array<int, string|int|null>>  $filas
     * @param  array<int, string>  $encabezados
     */
    public function __construct(
        private readonly array $filas,
        private readonly array $encabezados,
        private readonly string $titulo,
    ) {}

    /**
     * @return array<int, array<int, string|int|null>>
     */
    public function array(): array
    {
        return $this->filas;
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return $this->encabezados;
    }

    public function title(): string
    {
        return $this->titulo;
    }
}
