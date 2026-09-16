<?php

namespace App\Exports;

use App\Exports\Concerns\DecoraConContexto;
use App\Soporte\ContextoExportacion;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
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
 *
 * `WithStrictNullComparison`: sin ella, `Maatwebsite\Excel\Sheet::append()`
 * compara cada valor contra `null` con `!=` en vez de `!==`, y en PHP
 * `0 == null` — cualquier columna numérica en `0` (o `''`/`false`) de
 * CUALQUIER módulo que use este export quedaría vacía en la celda en vez de
 * mostrar el valor real. Afecta a todos los consumidores de `ListadoExport`
 * por igual; es un cambio puramente aditivo (sólo un `null` real se sigue
 * omitiendo) sin downside.
 */
class ListadoExport implements FromArray, ShouldAutoSize, WithEvents, WithHeadings, WithStrictNullComparison, WithTitle
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
        // Fijo: la hoja "Resumen" (título real del reporte + KPIs/gráficas)
        // la antepone `DecoraConContexto::construirHojaResumen()`; ésta es
        // siempre la hoja de datos crudos.
        return 'Datos';
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
