<?php

namespace App\Exports;

use App\Exports\Concerns\DecoraConContexto;
use App\Soporte\ContextoExportacion;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Export genérico de un listado ya resuelto a filas planas (mismas filas que
 * ve la pantalla y el PDF: una sola consulta por módulo, tres salidas). Los
 * exports más específicos (`EntregasExport`, `InventarioExport`) siguen
 * existiendo para reportes con forma propia; éste cubre el resto de módulos
 * (Empresas, Sucursales, Áreas, Almacenes, Conjuntos, Movimientos,
 * Devoluciones, Unidades, Colaboradores, Auditoría) sin repetir la misma
 * clase boilerplate una y otra vez. El encabezado corporativo (metadata +
 * logo + estilos) lo aporta `DecoraConContexto`, compartido con los exports
 * de forma propia.
 */
class ListadoExport implements FromArray, ShouldAutoSize, WithEvents, WithHeadings, WithTitle
{
    use DecoraConContexto;

    /**
     * @param  array<int, array<int, string|int|null>>  $filas
     * @param  array<int, string>  $encabezados
     */
    public function __construct(
        private readonly array $filas,
        private readonly array $encabezados,
        private readonly ContextoExportacion $contexto,
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
        // Los nombres de hoja de Excel no admiten : \ / ? * [ ] ni más de 31 caracteres.
        return Str::of($this->contexto->titulo)
            ->replaceMatches('/[:\\\\\/\?\*\[\]]/', ' ')
            ->limit(31, '')
            ->trim()
            ->value() ?: 'Reporte';
    }

    protected function contextoExportacion(): ContextoExportacion
    {
        return $this->contexto;
    }

    protected function totalFilas(): int
    {
        return count($this->filas);
    }
}
