<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Plantilla descargable para la importación masiva de colaboradores.
 */
class PlantillaColaboradoresExport implements FromArray, WithHeadings, WithTitle
{
    /**
     * @param  array<int, mixed>  $codigosSucursal
     */
    public function __construct(private readonly array $codigosSucursal = []) {}

    /**
     * @return array<int, array<int, string>>
     */
    public function array(): array
    {
        $ejemploSucursal = (string) ($this->codigosSucursal[0] ?? 'MATRIZ');

        return [
            ['Juan Pérez López', 'PELJ850101HDFRZN08', 'Operador', 'Producción', 'juan.perez@example.test', $ejemploSucursal],
            ['María García Ruiz', 'GARM900215MDFRZR03', 'Supervisora', 'Almacén', '', $ejemploSucursal],
        ];
    }

    /**
     * El número de empleado NUNCA se captura en el Excel: lo genera el
     * backend (App\Soporte\GeneradorNumeroEmpleado), igual que en el alta
     * manual — un valor en esa columna simplemente se ignoraría.
     *
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['nombre_completo', 'curp', 'puesto', 'area', 'correo', 'sucursal_codigo'];
    }

    public function title(): string
    {
        return 'Colaboradores';
    }
}
