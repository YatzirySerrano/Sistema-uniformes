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
            ['1001', 'Juan Pérez López', 'Operador', 'Producción', 'juan.perez@example.test', $ejemploSucursal],
            ['1002', 'María García Ruiz', 'Supervisora', 'Almacén', '', $ejemploSucursal],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['numero_empleado', 'nombre_completo', 'puesto', 'area', 'correo', 'sucursal_codigo'];
    }

    public function title(): string
    {
        return 'Colaboradores';
    }
}
