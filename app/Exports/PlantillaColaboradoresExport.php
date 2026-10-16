<?php

namespace App\Exports;

use App\Servicios\ServicioImportacionColaboradores;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Plantilla descargable para la importación masiva de colaboradores. Las
 * filas de ejemplo usan códigos de la empresa elegida si existen; si no,
 * códigos ficticios con el formato del sistema.
 */
class PlantillaColaboradoresExport implements FromArray, WithHeadings, WithTitle
{
    public function __construct(
        private readonly ?string $codigoArea = null,
        private readonly ?string $codigoSucursal = null,
        private readonly ?string $codigoServicio = null,
    ) {}

    /**
     * @return array<int, array<int, string>>
     */
    public function array(): array
    {
        $area = $this->codigoArea ?? 'ARE-0001';
        $sucursal = $this->codigoSucursal ?? 'MATRIZ';
        $servicio = $this->codigoServicio ?? 'SER-0001';

        return [
            ['Juan Pérez López', 'PELJ850101HDFRZN08', 'Operador', $area, 'juan.perez@example.test', $sucursal, $servicio],
            ['María García Ruiz', 'GARM900215MQTRZR03', 'Supervisora', $area, '', $sucursal, $servicio],
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
        return ServicioImportacionColaboradores::COLUMNAS;
    }

    public function title(): string
    {
        return 'Colaboradores';
    }
}
