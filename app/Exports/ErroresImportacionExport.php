<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Reporte descargable de errores de la importación de base de datos maestra:
 * hoja/fila/campo/valor/error, para que el usuario corrija el Excel maestro
 * fuera del sistema. Tabla plana de 5 columnas, sin el "contexto" de
 * `ListadoExport` (no aplica empresa/filtros/logo a este reporte técnico).
 */
class ErroresImportacionExport implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    /**
     * @param  list<array{hoja: string, fila: int, campo: string|null, valor: mixed, error: string}>  $errores
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
                $e['hoja'],
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
        return ['Hoja', 'Fila', 'Campo', 'Valor', 'Error'];
    }

    public function title(): string
    {
        return 'Errores';
    }
}
