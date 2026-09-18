<?php

namespace App\Exports\Concerns;

use App\Models\ConfiguracionSistema;
use App\Servicios\ServicioGraficaImagen;
use App\Soporte\ContextoExportacion;
use App\Soporte\KpiExportacion;
use App\Soporte\SerieGraficaReporte;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
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
     * Ancho máximo (en unidades de columna de PhpSpreadsheet, ~caracteres)
     * para una columna de texto libre en el estilo administrativo — evita
     * que una descripción/motivo largo genere una columna gigantesca; el
     * contenido se envuelve (`wrap text`) en vez de ensanchar más.
     */
    private const ANCHO_MAXIMO_COLUMNA = 45;

    /**
     * @return array<string, callable>
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $evento): void {
                $contexto = $this->contextoExportacion();

                $this->decorarConContexto($evento->sheet->getDelegate(), $contexto, $this->totalEncabezados(), $this->totalFilas());

                // Hoja "Resumen" separada SÓLO cuando aporta algo que la hoja
                // "Datos" no tiene ya (KPIs y/o gráficas): un reporte simple
                // sin ninguno de los dos ya trae título/empresa/filtros/
                // generado por/total en el bloque de metadata de "Datos"
                // (`decorarConContexto()`) — una segunda hoja repitiendo
                // exactamente lo mismo no aporta valor, sólo confunde.
                if ($contexto->kpis !== [] || $contexto->graficas !== []) {
                    $this->construirHojaResumen($evento->sheet->getDelegate()->getParentOrThrow(), $contexto);
                }
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

    /**
     * `true` (por defecto — `ListadoExport`, todos los listados
     * administrativos): usa el estilo "ejecutivo" nuevo (banda de
     * metadata, título destacado, columnas con ancho máximo + wrap,
     * pie de página). `EntregasExport`/`InventarioExport` (Reportes) lo
     * sobrescriben a `false` para conservar EXACTAMENTE su diseño anterior
     * — Reportes tiene su propio criterio visual y no debe cambiar aquí.
     */
    protected function estiloAdministrativo(): bool
    {
        return true;
    }

    /**
     * Color de acento reutilizado de forma segura: el mismo
     * `ConfiguracionSistema::color_principal` que ya usa el resto del
     * sistema (sidebar, botones, gráficas — `PaletaGraficas::principal()`).
     * Sólo para TEXTO/bordes finos, nunca para el relleno grande del
     * encabezado de tabla (que se queda en el azul-slate sobrio fijo,
     * `1E293B`, para blindarse de un color de marca poco profesional que
     * un cliente haya configurado). Con fallback seguro si el valor
     * guardado no es un hex válido.
     */
    private function colorAccentoAdministrativo(): string
    {
        $color = ltrim((string) (ConfiguracionSistema::actual()->color_principal ?? ''), '#');

        return preg_match('/^[0-9A-Fa-f]{6}$/', $color) === 1 ? Str::upper($color) : '1E293B';
    }

    private function decorarConContexto(Worksheet $hoja, ContextoExportacion $contexto, int $numEncabezados, int $numFilas): void
    {
        $administrativo = $this->estiloAdministrativo();
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

        // La última fila insertada (`$numFilasMeta`) queda en blanco a
        // propósito: es el respiro entre la banda de metadata y la tabla.
        $numFilasMeta = count($metadatos) + 1;
        $hoja->insertNewRowBefore(1, $numFilasMeta);

        foreach ($metadatos as $indice => [$etiqueta, $valor]) {
            $fila = $indice + 1;
            $hoja->setCellValue("A{$fila}", $etiqueta.':');
            $hoja->setCellValue("B{$fila}", $valor);
            $hoja->getStyle("A{$fila}")->getFont()->setBold(true);
        }

        if ($administrativo) {
            $colorAccento = $this->colorAccentoAdministrativo();

            // Título del reporte (fila 1) destacado — la banda entera se
            // lee como una sola unidad visual, no líneas de texto sueltas.
            $hoja->getStyle('B1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB($colorAccento);
            $hoja->getStyle('A1')->getFont()->setBold(true)->setSize(9)->getColor()->setRGB('64748B');
            $hoja->getRowDimension(1)->setRowHeight(24);

            $rangoBanda = "A1:{$ultimaColumna}{$numFilasMeta}";
            $hoja->getStyle($rangoBanda)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F1F5F9');
            $hoja->getStyle("A{$numFilasMeta}:{$ultimaColumna}{$numFilasMeta}")->getBorders()->getBottom()
                ->setBorderStyle(Border::BORDER_MEDIUM)->setColor(new Color('CBD5E1'));
        }

        $filaEncabezados = $numFilasMeta + 1;
        $ultimaFila = $filaEncabezados + $numFilas;
        $rangoEncabezados = "A{$filaEncabezados}:{$ultimaColumna}{$filaEncabezados}";

        $hoja->getStyle($rangoEncabezados)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $hoja->getStyle($rangoEncabezados)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1E293B');
        $hoja->getStyle($rangoEncabezados)->getAlignment()->setVertical('center')->setHorizontal('center');
        $hoja->getRowDimension($filaEncabezados)->setRowHeight($administrativo ? 20 : 15);

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

            // Impresión: repite la fila de encabezados en cada página y
            // ajusta las columnas al ancho de la hoja (nunca el alto, para no
            // comprimir filas) — para que la tabla se siga leyendo bien
            // aunque tenga muchas filas o se imprima/exporte a PDF desde Excel.
            $hoja->getPageSetup()
                ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
                ->setFitToWidth(1)
                ->setFitToHeight(0)
                ->setRowsToRepeatAtTopByStartAndEnd($filaEncabezados, $filaEncabezados);

            if ($administrativo) {
                $this->ajustarColumnasYAlineacion($hoja, $numColumnas, $filaEncabezados, $ultimaFila);
                $this->pintarPiePaginaAdministrativo($hoja, $contexto);
            }
        }

        $this->dibujarLogoDeContexto($hoja, $contexto, $numColumnas);
    }

    /**
     * Recorre las columnas de datos YA ESCRITAS (después de `FromArray`/
     * `FromCollection`) para:
     *   - alinear centrado lo que en TODA la columna luce como fecha
     *     (`dd/mm/aaaa`) y a la derecha lo que es numérico — nunca adivina
     *     por el nombre del encabezado, sólo por el contenido real;
     *   - poner un ancho MÁXIMO razonable + `wrap text` a cualquier columna
     *     cuyo contenido más largo sea de texto libre (descripciones,
     *     motivos…), para que no salga una columna gigantesca — el resto de
     *     columnas conserva el auto-ancho normal de `ShouldAutoSize`.
     * No cambia ningún valor, sólo estilo — no se puede leer el ancho
     * "auto" aquí (PhpSpreadsheet lo calcula hasta escribir el archivo), así
     * que el máximo se decide por la longitud real del contenido.
     */
    private function ajustarColumnasYAlineacion(Worksheet $hoja, int $numColumnas, int $filaEncabezados, int $ultimaFila): void
    {
        $primeraFilaDatos = $filaEncabezados + 1;
        if ($primeraFilaDatos > $ultimaFila) {
            return;
        }

        for ($col = 1; $col <= $numColumnas; $col++) {
            $colLetra = Coordinate::stringFromColumnIndex($col);
            $maxLargo = 0;
            $todasFecha = true;
            $todasNumerico = true;
            $hayValor = false;

            for ($fila = $primeraFilaDatos; $fila <= $ultimaFila; $fila++) {
                $valor = $hoja->getCellByColumnAndRow($col, $fila)->getValue();
                if ($valor === null || $valor === '') {
                    continue;
                }

                $hayValor = true;
                $texto = (string) $valor;
                $maxLargo = max($maxLargo, mb_strlen($texto));

                if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $texto) !== 1) {
                    $todasFecha = false;
                }
                if (! is_numeric($valor)) {
                    $todasNumerico = false;
                }
            }

            if (! $hayValor) {
                continue;
            }

            $rangoColumna = "{$colLetra}{$primeraFilaDatos}:{$colLetra}{$ultimaFila}";

            if ($todasFecha) {
                $hoja->getStyle($rangoColumna)->getAlignment()->setHorizontal('center');
            } elseif ($todasNumerico) {
                $hoja->getStyle($rangoColumna)->getAlignment()->setHorizontal('right');
            }

            if ($maxLargo > self::ANCHO_MAXIMO_COLUMNA) {
                $hoja->getColumnDimension($colLetra)->setAutoSize(false)->setWidth(self::ANCHO_MAXIMO_COLUMNA);
                $hoja->getStyle($rangoColumna)->getAlignment()->setWrapText(true)->setVertical('top');
            }
        }
    }

    /**
     * Pie de página de IMPRESIÓN (sólo visible al imprimir/exportar a PDF
     * desde Excel, nunca en pantalla): nombre del sistema a la izquierda,
     * "Página X de Y" al centro, fecha/hora de generación a la derecha.
     */
    private function pintarPiePaginaAdministrativo(Worksheet $hoja, ContextoExportacion $contexto): void
    {
        // Nombre del sistema (config, controlado) y fecha ya formateada
        // (`generadoEnLocal()`, controlada) — ninguno de los dos es texto
        // libre de usuario, así que no requieren escapar `&`.
        $nombreSistema = (string) config('app.name', 'Sistema');
        $fecha = $contexto->generadoEnLocal();

        $hoja->getHeaderFooter()->setOddFooter("&L&8{$nombreSistema}&C&8Página &P de &N&R&8{$fecha}");
    }

    /**
     * Hoja "Resumen": se antepone (índice 0) a la hoja de datos. Título +
     * empresa + logo + "Generado por" + tarjetas KPI — todo derivado de
     * `$contexto`, sin volver a consultar nada. Las gráficas NO van aquí:
     * ver `construirHojasGraficas()`.
     */
    private function construirHojaResumen(Spreadsheet $libro, ContextoExportacion $contexto): void
    {
        $administrativo = $this->estiloAdministrativo();
        $hoja = $libro->createSheet(0);
        $hoja->setTitle('Resumen');

        $hoja->setCellValue('A1', $contexto->titulo);
        $hoja->getStyle('A1')->getFont()->setBold(true)->setSize(16)
            ->getColor()->setRGB($administrativo ? $this->colorAccentoAdministrativo() : '0F172A');
        // Alto explícito para que el título de 16pt nunca se vea cortado —
        // `dibujarLogoDeContexto()` sólo lo ajusta cuando hay logo.
        $hoja->getRowDimension(1)->setRowHeight(26);

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
            $this->pintarTarjetasKpi($hoja, $contexto->kpisResueltos(), $filaCursor, $administrativo);
        }

        $this->dibujarLogoDeContexto($hoja, $contexto, 8);

        // Hasta 'L': con descripción, las tarjetas usan 3 columnas cada una
        // (3 por fila = 9 columnas, A-I); sin descripción siguen siendo 2
        // columnas (4 por fila = 8 columnas, A-H) — 'L' cubre ambos casos
        // con margen sin angostar ninguna columna real de la cuadrícula.
        $hoja->getColumnDimension('A')->setWidth(30);
        foreach (range('B', 'L') as $columna) {
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
     * Cuadrícula de tarjetas KPI: etiqueta arriba, valor grande al centro,
     * borde + fill suave alrededor de la tarjeta completa. Si al MENOS un
     * KPI trae descripción (`ContextoExportacion::$kpiDescripciones`), la
     * tarjeta gana una 3ª línea de texto corrido (envuelto, alineado a la
     * izquierda) y se ensancha (3 por fila en vez de 4) para que ese texto
     * quepa sin comprimirse — retrocompatible: un módulo que nunca pasó
     * descripciones (todos los `KpiExportacion::$descripcion` en null) sigue
     * viendo exactamente la cuadrícula de siempre, 1:1.
     *
     * @param  array<int, KpiExportacion>  $kpis
     */
    private function pintarTarjetasKpi(Worksheet $hoja, array $kpis, int $filaInicio, bool $administrativo = false): int
    {
        // `array_any()` es de PHP 8.4; `composer.json` todavía admite ^8.3.
        $tieneDescripciones = array_filter($kpis, fn (KpiExportacion $kpi): bool => $kpi->descripcion !== null) !== [];

        $porFila = $tieneDescripciones ? 3 : 4;
        $anchoTarjeta = $tieneDescripciones ? 3 : 2;
        $altoTarjeta = $tieneDescripciones ? 4 : 3;
        $filaMax = $filaInicio;

        foreach ($kpis as $indice => $kpi) {
            $fila = $filaInicio + intdiv($indice, $porFila) * $altoTarjeta;
            $colInicio = 1 + ($indice % $porFila) * $anchoTarjeta;
            $colInicioLetra = Coordinate::stringFromColumnIndex($colInicio);
            $colFinLetra = Coordinate::stringFromColumnIndex($colInicio + $anchoTarjeta - 1);
            $filaValor = $fila + 1;
            $filaFinTarjeta = $filaValor;

            $hoja->mergeCells("{$colInicioLetra}{$fila}:{$colFinLetra}{$fila}");
            $hoja->setCellValue("{$colInicioLetra}{$fila}", $kpi->etiqueta);
            $hoja->getStyle("{$colInicioLetra}{$fila}")->getFont()->setSize(9)->getColor()->setRGB('64748B');

            $hoja->mergeCells("{$colInicioLetra}{$filaValor}:{$colFinLetra}{$filaValor}");
            $hoja->setCellValue("{$colInicioLetra}{$filaValor}", $kpi->valor);
            $hoja->getStyle("{$colInicioLetra}{$filaValor}")->getFont()->setBold(true)->setSize(15)->getColor()->setRGB('0F172A');
            $hoja->getStyle("{$colInicioLetra}{$fila}:{$colFinLetra}{$filaValor}")->getAlignment()->setHorizontal('center')->setVertical('center');

            if ($kpi->descripcion !== null) {
                $filaDescripcion = $filaValor + 1;
                $hoja->mergeCells("{$colInicioLetra}{$filaDescripcion}:{$colFinLetra}{$filaDescripcion}");
                $hoja->setCellValue("{$colInicioLetra}{$filaDescripcion}", $kpi->descripcion);
                $hoja->getStyle("{$colInicioLetra}{$filaDescripcion}")->getFont()->setSize(8)->getColor()->setRGB('64748B');
                $hoja->getStyle("{$colInicioLetra}{$filaDescripcion}")->getAlignment()
                    ->setHorizontal('left')->setVertical('top')->setWrapText(true);
                $hoja->getRowDimension($filaDescripcion)->setRowHeight(36);
                $filaFinTarjeta = $filaDescripcion;
            }

            $rango = "{$colInicioLetra}{$fila}:{$colFinLetra}{$filaFinTarjeta}";
            $hoja->getStyle($rango)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('E2E8F0'));
            $hoja->getStyle($rango)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8FAFC');

            // Franja superior de acento (look de "tile" ejecutivo) — sólo en
            // el estilo administrativo, para no alterar las tarjetas de
            // Reportes.
            if ($administrativo) {
                $hoja->getStyle("{$colInicioLetra}{$fila}:{$colFinLetra}{$fila}")->getBorders()->getTop()
                    ->setBorderStyle(Border::BORDER_MEDIUM)->setColor(new Color($this->colorAccentoAdministrativo()));
            }

            $filaMax = max($filaMax, $fila + $altoTarjeta - 1);
        }

        return $filaMax;
    }

    /**
     * Tabla de respaldo con diseño corporativo (encabezado oscuro, bandas
     * alternas, bordes suaves — mismo lenguaje visual que la hoja "Datos") +
     * gráfica incrustada como IMAGEN PNG (`ServicioGraficaImagen`, mismo
     * ApexCharts/paleta que el PDF), anclada aparte a la derecha, con una
     * columna de aire de por medio para que tabla y gráfica nunca se
     * solapen. Los bordes/bandas sólo cubren título + encabezado + filas
     * CON datos reales — nunca se "reserva" espacio pintando filas vacías
     * como si fueran parte de la tabla. Si Chromium no está disponible o
     * falla, la tabla de respaldo sigue mostrando los datos — nunca un 500
     * por una gráfica que no se pudo renderizar.
     */
    private function pintarGraficaExcel(Worksheet $hoja, SerieGraficaReporte $grafica, int $filaInicio): int
    {
        $numColumnasTabla = $grafica->esComparativa() ? 3 : 2;
        $ultimaColumnaTabla = Coordinate::stringFromColumnIndex($numColumnasTabla);

        $hoja->mergeCells("A{$filaInicio}:{$ultimaColumnaTabla}{$filaInicio}");
        $hoja->setCellValue("A{$filaInicio}", $grafica->titulo);
        $hoja->getStyle("A{$filaInicio}")->getFont()->setBold(true)->setSize(12)->getColor()->setRGB('0F172A');
        $hoja->getRowDimension($filaInicio)->setRowHeight(22);

        $filaEncabezado = $filaInicio + 2;

        if ($grafica->esComparativa()) {
            $hoja->setCellValue("A{$filaEncabezado}", 'Periodo');
            $hoja->setCellValue("B{$filaEncabezado}", $grafica->etiquetaSerie ?? $grafica->titulo);
            $hoja->setCellValue("C{$filaEncabezado}", $grafica->etiquetaComparacion);
        } else {
            $hoja->setCellValue("A{$filaEncabezado}", 'Categoría');
            $hoja->setCellValue("B{$filaEncabezado}", $grafica->etiquetaSerie ?? 'Valor');
        }

        $rangoEncabezado = "A{$filaEncabezado}:{$ultimaColumnaTabla}{$filaEncabezado}";
        $hoja->getStyle($rangoEncabezado)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $hoja->getStyle($rangoEncabezado)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1E293B');
        $hoja->getStyle($rangoEncabezado)->getAlignment()->setVertical('center');
        $hoja->getRowDimension($filaEncabezado)->setRowHeight(18);

        $filaDatos = $filaEncabezado + 1;
        $numFilasDatos = count($grafica->etiquetas);

        foreach ($grafica->etiquetas as $i => $etiqueta) {
            $fila = $filaDatos + $i;
            $hoja->setCellValue("A{$fila}", $etiqueta);
            $hoja->setCellValue("B{$fila}", $grafica->valores[$i] ?? 0);
            if ($grafica->esComparativa()) {
                $hoja->setCellValue("C{$fila}", $grafica->valoresComparacion[$i] ?? 0);
            }

            if ($i % 2 === 1) {
                $hoja->getStyle("A{$fila}:{$ultimaColumnaTabla}{$fila}")
                    ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8FAFC');
            }
        }

        // Nunca filas vacías "reservadas": si no hay datos, el borde cierra
        // en el propio encabezado — la tabla no sigue más allá de lo real.
        $filaFinTabla = $numFilasDatos > 0 ? $filaDatos + $numFilasDatos - 1 : $filaEncabezado;

        $rangoTabla = "A{$filaEncabezado}:{$ultimaColumnaTabla}{$filaFinTabla}";
        $hoja->getStyle($rangoTabla)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('CBD5E1'));

        if ($numFilasDatos > 0) {
            $hoja->getStyle("B{$filaDatos}:{$ultimaColumnaTabla}{$filaFinTabla}")->getAlignment()->setHorizontal('right');
            $hoja->getStyle("A{$filaDatos}:A{$filaFinTabla}")->getAlignment()->setHorizontal('left');
        }

        // Columna de aire tras la tabla para que la imagen nunca se solape
        // (2 en vez de 2 fijo: si la comparativa usa 3 columnas de tabla,
        // la gráfica arranca en F, no en D).
        $columnaImagen = Coordinate::stringFromColumnIndex($numColumnasTabla + 2);
        $rutaImagen = app(ServicioGraficaImagen::class)->generarPng($grafica);

        if ($rutaImagen !== null) {
            $dibujo = new Drawing;
            $dibujo->setPath($rutaImagen);
            $dibujo->setName($grafica->titulo);
            $dibujo->setResizeProportional(false);
            $dibujo->setWidth(608);
            $dibujo->setHeight(320);
            $dibujo->setCoordinates("{$columnaImagen}{$filaInicio}");
            $dibujo->setOffsetX(4);
            $dibujo->setOffsetY(4);
            $dibujo->setWorksheet($hoja);
        }

        return $filaFinTabla;
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
