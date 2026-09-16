<?php

namespace App\Exports\Concerns;

use App\Servicios\ServicioGraficaImagen;
use App\Soporte\ContextoExportacion;
use App\Soporte\SerieGraficaReporte;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Encabezado corporativo + "reporte ejecutivo" compartido para exports Excel
 * (`ListadoExport` y los exports con forma propia `EntregasExport`/
 * `InventarioExport`): la hoja de datos (`title()` de cada clase consumidora
 * siempre es "Datos") recibe el bloque de metadata + tabla con encabezado
 * destacado + bandas alternas + autofiltro + freeze pane; y se antepone una
 * hoja "Resumen" con título/empresa/logo, "Generado por", tarjetas KPI
 * (`ContextoExportacion::$kpis`) y una hoja POR GRÁFICA
 * (`ContextoExportacion::$graficas`) cuando el módulo las aporta. Cada clase
 * consumidora sólo implementa `WithEvents::registerEvents()` delegando aquí
 * y expone el número de filas de datos + encabezados para calcular los
 * rangos.
 *
 * Las gráficas se incrustan como IMAGEN PNG (`ServicioGraficaImagen`, mismo
 * ApexCharts/paleta que el PDF), nunca como
 * `PhpOffice\PhpSpreadsheet\Chart\Chart` nativo: Apple Numbers 14.4 cierra la
 * app al ENTRAR a cualquier hoja con un `<c:chart>` OOXML nativo de
 * PhpSpreadsheet (crash en `TSCharts`/`TSCHSeriesStyleForSeriesIndex`), sin
 * importar si los caches de punto están completos o si hay una sola gráfica
 * por hoja — el objeto chart en sí es el disparador. Una imagen normal es
 * inmune por construcción. Por eso ningún export de este trait implementa ya
 * `Maatwebsite\Excel\Concerns\WithCharts` (era sólo la bandera que activaba
 * `setIncludeCharts(true)` en el writer; sin `Chart` nativos que serializar,
 * no aporta nada).
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
                $contexto = $this->contextoExportacion();

                $this->decorarConContexto($evento->sheet->getDelegate(), $contexto, $this->totalEncabezados(), $this->totalFilas());
                $this->construirHojaResumen($evento->sheet->getDelegate()->getParentOrThrow(), $contexto);
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
            ['Generado por', $contexto->generadoPor ?? 'Sistema'],
            ['Generado', $contexto->generadoEnLocal()],
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
     * Hoja "Resumen": se antepone (índice 0) a la hoja de datos. Título +
     * empresa + logo + "Generado por" + tarjetas KPI — todo derivado de
     * `$contexto`, sin volver a consultar nada. Las gráficas NO van aquí:
     * ver `construirHojasGraficas()`.
     */
    private function construirHojaResumen(Spreadsheet $libro, ContextoExportacion $contexto): void
    {
        $hoja = $libro->createSheet(0);
        $hoja->setTitle('Resumen');

        $hoja->setCellValue('A1', $contexto->titulo);
        $hoja->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('0F172A');

        $hoja->setCellValue('A2', $contexto->nombreEmpresa());
        $hoja->getStyle('A2')->getFont()->setSize(11)->getColor()->setRGB('334155');

        $hoja->setCellValue('A3', 'Generado por: '.($contexto->generadoPor ?? 'Sistema').'  ·  '.$contexto->generadoEnLocal());
        $hoja->getStyle('A3')->getFont()->setSize(9)->getColor()->setRGB('64748B');

        $filaCursor = 5;

        if ($contexto->filtros !== []) {
            $filtrosTexto = 'Filtros: '.implode('  ·  ', array_map(
                fn (string $etiqueta, string $valor): string => "{$etiqueta}: {$valor}",
                array_keys($contexto->filtros),
                array_values($contexto->filtros),
            ));
            $hoja->setCellValue('A4', $filtrosTexto);
            $hoja->getStyle('A4')->getFont()->setSize(9)->setItalic(true)->getColor()->setRGB('64748B');
        }

        $hoja->setCellValue("A{$filaCursor}", 'Total de registros: '.$contexto->total);
        $hoja->getStyle("A{$filaCursor}")->getFont()->setBold(true)->setSize(10);
        $filaCursor += 2;

        if ($contexto->kpis !== []) {
            $this->pintarTarjetasKpi($hoja, $contexto->kpis, $filaCursor);
        }

        $this->dibujarLogoDeContexto($hoja, $contexto, 8);

        $hoja->getColumnDimension('A')->setWidth(30);
        foreach (range('B', 'H') as $columna) {
            $hoja->getColumnDimension($columna)->setWidth(14);
        }

        $this->construirHojasGraficas($libro, $contexto);
    }

    /**
     * Cada gráfica en su PROPIA hoja, como IMAGEN (ver docblock del trait) —
     * nunca dos gráficas comparten hoja, y nunca un `Chart` OOXML nativo.
     */
    private function construirHojasGraficas(Spreadsheet $libro, ContextoExportacion $contexto): void
    {
        $nombresUsados = [];

        foreach ($contexto->graficas as $grafica) {
            $nombre = $this->nombreHojaGraficaUnico($grafica->titulo, $nombresUsados);
            $nombresUsados[] = $nombre;

            $hoja = $libro->createSheet();
            $hoja->setTitle($nombre);

            $this->pintarGraficaExcel($hoja, $grafica, 1);

            $hoja->getColumnDimension('A')->setWidth(30);
            $hoja->getColumnDimension('B')->setWidth(14);
            $hoja->getColumnDimension('C')->setWidth(14);
        }
    }

    /**
     * Nombre de hoja válido para Excel (máx. 31 caracteres, sin
     * `: \ / ? * [ ]`) y único dentro del libro — si dos gráficas truncan al
     * mismo nombre, añade un sufijo numérico.
     *
     * @param  array<int, string>  $usados
     */
    private function nombreHojaGraficaUnico(string $titulo, array $usados): string
    {
        $base = Str::of($titulo)->replaceMatches('/[:\\\\\/\?\*\[\]]/', ' ')->limit(27, '')->trim()->value();
        $base = $base === '' ? 'Gráfica' : $base;

        $nombre = $base;
        for ($sufijo = 2; in_array($nombre, $usados, true); $sufijo++) {
            $nombre = Str::of($base)->limit(31 - strlen(" ({$sufijo})"), '')->trim()->value()." ({$sufijo})";
        }

        return $nombre;
    }

    /**
     * Cuadrícula de tarjetas KPI (4 por fila): etiqueta arriba, valor grande
     * abajo, borde + fill suave alrededor de ambas celdas fusionadas.
     *
     * @param  array<string, string|int>  $kpis
     */
    private function pintarTarjetasKpi(Worksheet $hoja, array $kpis, int $filaInicio): int
    {
        $porFila = 4;
        $anchoTarjeta = 2;
        $altoTarjeta = 3;
        $filaMax = $filaInicio;
        $indice = 0;

        foreach ($kpis as $etiqueta => $valor) {
            $fila = $filaInicio + intdiv($indice, $porFila) * $altoTarjeta;
            $colInicio = 1 + ($indice % $porFila) * $anchoTarjeta;
            $colInicioLetra = Coordinate::stringFromColumnIndex($colInicio);
            $colFinLetra = Coordinate::stringFromColumnIndex($colInicio + $anchoTarjeta - 1);
            $filaValor = $fila + 1;

            $hoja->mergeCells("{$colInicioLetra}{$fila}:{$colFinLetra}{$fila}");
            $hoja->setCellValue("{$colInicioLetra}{$fila}", (string) $etiqueta);
            $hoja->getStyle("{$colInicioLetra}{$fila}")->getFont()->setSize(9)->getColor()->setRGB('64748B');

            $hoja->mergeCells("{$colInicioLetra}{$filaValor}:{$colFinLetra}{$filaValor}");
            $hoja->setCellValue("{$colInicioLetra}{$filaValor}", $valor);
            $hoja->getStyle("{$colInicioLetra}{$filaValor}")->getFont()->setBold(true)->setSize(15)->getColor()->setRGB('0F172A');

            $rango = "{$colInicioLetra}{$fila}:{$colFinLetra}{$filaValor}";
            $hoja->getStyle($rango)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('E2E8F0'));
            $hoja->getStyle($rango)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8FAFC');
            $hoja->getStyle($rango)->getAlignment()->setHorizontal('center')->setVertical('center');

            $filaMax = max($filaMax, $fila + $altoTarjeta - 1);
            $indice++;
        }

        return $filaMax;
    }

    /**
     * Tabla de respaldo (etiqueta/valor) + gráfica incrustada como IMAGEN PNG
     * (`ServicioGraficaImagen`, mismo ApexCharts/paleta que el PDF) anclada a
     * la derecha de la tabla. Si Chromium no está disponible o falla, la
     * tabla de respaldo sigue mostrando los datos — nunca un 500 por una
     * gráfica que no se pudo renderizar.
     */
    private function pintarGraficaExcel(Worksheet $hoja, SerieGraficaReporte $grafica, int $filaInicio): int
    {
        $hoja->setCellValue("A{$filaInicio}", $grafica->titulo);
        $hoja->getStyle("A{$filaInicio}")->getFont()->setBold(true)->setSize(11);

        $filaTabla = $filaInicio + 1;
        $numFilas = max(count($grafica->etiquetas), 1);

        if ($grafica->esComparativa()) {
            $hoja->setCellValue("A{$filaTabla}", 'Periodo');
            $hoja->setCellValue("B{$filaTabla}", $grafica->etiquetaSerie ?? $grafica->titulo);
            $hoja->setCellValue("C{$filaTabla}", $grafica->etiquetaComparacion);
            $hoja->getStyle("A{$filaTabla}:C{$filaTabla}")->getFont()->setBold(true);
            $filaTabla++;
        }

        foreach ($grafica->etiquetas as $i => $etiqueta) {
            $fila = $filaTabla + $i;
            $hoja->setCellValue("A{$fila}", $etiqueta);
            $hoja->setCellValue("B{$fila}", $grafica->valores[$i] ?? 0);
            if ($grafica->esComparativa()) {
                $hoja->setCellValue("C{$fila}", $grafica->valoresComparacion[$i] ?? 0);
            }
        }

        $filaFinTabla = $filaTabla + $numFilas - 1;

        $rutaImagen = app(ServicioGraficaImagen::class)->generarPng($grafica);

        if ($rutaImagen !== null) {
            $dibujo = new Drawing;
            $dibujo->setPath($rutaImagen);
            $dibujo->setName($grafica->titulo);
            $dibujo->setResizeProportional(false);
            $dibujo->setWidth(608);
            $dibujo->setHeight(320);
            $dibujo->setCoordinates("D{$filaInicio}");
            $dibujo->setOffsetX(4);
            $dibujo->setOffsetY(4);
            $dibujo->setWorksheet($hoja);
        }

        return max($filaFinTabla, $filaInicio + 15);
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

            // Tamaño mediano (antes 45px, casi ilegible en pantallas grandes):
            // suficiente para reconocer el logo sin dominar el encabezado.
            $dibujo = new Drawing;
            $dibujo->setPath($ruta);
            $dibujo->setHeight(64);
            $dibujo->setCoordinates($columnaDibujo.'1');
            $dibujo->setOffsetY(2);
            $dibujo->setWorksheet($hoja);
            $hoja->getRowDimension(1)->setRowHeight(50);
        } catch (Throwable) {
            // El export sigue sin logo — nunca un 500 por un archivo dañado.
        }
    }
}
