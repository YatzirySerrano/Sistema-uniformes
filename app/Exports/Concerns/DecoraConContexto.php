<?php

namespace App\Exports\Concerns;

use App\Soporte\ContextoExportacion;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Encabezado corporativo compartido para exports Excel (`ListadoExport` y los
 * exports con forma propia `EntregasExport`/`InventarioExport`): bloque de
 * metadata (reporte, empresa, filtros humanizados, fecha, total) + tabla con
 * encabezado destacado, bandas alternas, autofiltro y freeze pane, más el
 * logo de la empresa cuando aplica. Cada clase consumidora sólo implementa
 * `WithEvents::registerEvents()` delegando aquí y expone el número de filas
 * de datos + encabezados para calcular los rangos.
 */
trait DecoraConContexto
{
    /**
     * @return array<string, callable>
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $evento): void {
                $this->decorarConContexto($evento->sheet->getDelegate(), $this->contextoExportacion(), $this->totalEncabezados(), $this->totalFilas());
            },
        ];
    }

    abstract protected function contextoExportacion(): ContextoExportacion;

    /**
     * Número de filas de DATOS realmente exportadas (no de registros de
     * origen: p. ej. `EntregasExport` explota cada entrega en una fila por
     * renglón, así que su total no es `count($this->entregas)`).
     */
    abstract protected function totalFilas(): int;

    protected function totalEncabezados(): int
    {
        return count($this->headings());
    }

    private function decorarConContexto(Worksheet $hoja, ContextoExportacion $contexto, int $numEncabezados, int $numFilas): void
    {
        $numColumnas = max($numEncabezados, 1);
        $ultimaColumna = Coordinate::stringFromColumnIndex($numColumnas);

        $metadatos = [
            ['Reporte', $contexto->titulo],
            ['Empresa', $contexto->nombreEmpresa()],
            ...array_map(
                fn (string $etiqueta, string $valor): array => [$etiqueta, $valor],
                array_keys($contexto->filtros),
                array_values($contexto->filtros),
            ),
            ['Generado', $contexto->generadoEn->format('d/m/Y H:i')],
            ['Registros', (string) $contexto->total],
        ];

        $numFilasMeta = count($metadatos) + 1;
        $hoja->insertNewRowBefore(1, $numFilasMeta);

        foreach ($metadatos as $indice => [$etiqueta, $valor]) {
            $fila = $indice + 1;
            $hoja->setCellValue("A{$fila}", $etiqueta.':');
            $hoja->setCellValue("B{$fila}", $valor);
            $hoja->getStyle("A{$fila}")->getFont()->setBold(true);
        }

        $filaEncabezados = $numFilasMeta + 1;
        $ultimaFila = $filaEncabezados + $numFilas;
        $rangoEncabezados = "A{$filaEncabezados}:{$ultimaColumna}{$filaEncabezados}";

        $hoja->getStyle($rangoEncabezados)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $hoja->getStyle($rangoEncabezados)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1E293B');
        $hoja->getStyle($rangoEncabezados)->getAlignment()->setVertical('center');

        if ($ultimaFila > $filaEncabezados) {
            $rangoTabla = "A{$filaEncabezados}:{$ultimaColumna}{$ultimaFila}";
            $hoja->setAutoFilter($rangoTabla);
            $hoja->freezePane('A'.($filaEncabezados + 1));
            $hoja->getStyle($rangoTabla)->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('CBD5E1'));

            for ($fila = $filaEncabezados + 1; $fila <= $ultimaFila; $fila++) {
                if (($fila - $filaEncabezados) % 2 === 0) {
                    $hoja->getStyle("A{$fila}:{$ultimaColumna}{$fila}")
                        ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8FAFC');
                }
            }
        }

        $this->dibujarLogoDeContexto($hoja, $contexto, $numColumnas);
    }

    /**
     * Incrusta el logo (PNG/JPG) en la esquina del bloque de metadata. Nunca
     * hace fallar el export: si el logo no existe, es SVG (no soportado por
     * PhpSpreadsheet) o el dibujo falla por cualquier motivo, se omite.
     */
    private function dibujarLogoDeContexto(Worksheet $hoja, ContextoExportacion $contexto, int $numColumnas): void
    {
        $ruta = $contexto->logoRutaAbsoluta();

        if ($ruta === null) {
            return;
        }

        try {
            $columnaDibujo = Coordinate::stringFromColumnIndex(max(4, $numColumnas));

            $dibujo = new Drawing;
            $dibujo->setPath($ruta);
            $dibujo->setHeight(45);
            $dibujo->setCoordinates($columnaDibujo.'1');
            $dibujo->setWorksheet($hoja);
            $hoja->getRowDimension(1)->setRowHeight(36);
        } catch (Throwable) {
            // El export sigue sin logo — nunca un 500 por un archivo dañado.
        }
    }
}
