<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Reporte descargable de errores/duplicados de la importación de
 * colaboradores: fila/campo/valor/error, para corregir el Excel fuera del
 * sistema. Sin columna "Hoja" (a diferencia de `ErroresImportacionExport`,
 * de la importación maestra): este importador siempre trabaja con una sola
 * hoja.
 */
class ErroresImportacionColaboradoresExport implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    /**
     * @param  list<array{fila: int, campo: string|null, valor: mixed, error: string}>  $errores
     */
    public function __construct(private readonly array $errores) {}

    /**
     * @return array<int, array<int, bool|float|int|string|null>>
     */
    public function array(): array
    {
        return array_map(function (array $e): array {
            $valor = $e['valor'] ?? null;

            return [
                $e['fila'],
                $e['campo'] ?? '',
                match (true) {
                    $valor === null => '',
                    is_scalar($valor) => $valor,
                    default => (string) json_encode($valor),
                },
                $e['error'],
            ];
        }, $this->errores);
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Fila', 'Campo', 'Valor', 'Error'];
    }

    public function title(): string
    {
        return 'Errores';
    }
}
