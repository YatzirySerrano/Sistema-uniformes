<?php

namespace App\Servicios;

use App\Soporte\SerieGraficaReporte;
use Illuminate\Support\Facades\View;
use Spatie\Browsershot\Browsershot;
use Throwable;

/**
 * Convierte una `SerieGraficaReporte` en un PNG (mismo ApexCharts/paleta que
 * el PDF ejecutivo — nunca una consulta ni una librería de gráficas aparte)
 * para incrustarla en el Excel como IMAGEN en vez de como
 * `PhpOffice\PhpSpreadsheet\Chart\Chart` nativo.
 *
 * CRÍTICO (Apple Numbers 14.4): incluso con los caches de punto completos y
 * una sola gráfica por hoja, Numbers sigue cerrándose al ENTRAR a cualquier
 * hoja con una gráfica OOXML nativa de PhpSpreadsheet (crash en `TSCharts`/
 * `TSCHSeriesStyleForSeriesIndex`) — el problema es el objeto `<c:chart>` en
 * sí, no su contenido. Una imagen PNG dentro de un `Drawing` normal no pasa
 * por ese parser de Numbers en absoluto, así que es inmune por construcción.
 *
 * Usa el mismo Browsershot/Chromium que `spatie/laravel-pdf` (misma
 * configuración de `config('laravel-pdf.browsershot')`, ver
 * `AppServiceProvider::configureReportesPdf()`) — ninguna dependencia nueva.
 */
class ServicioGraficaImagen
{
    private const ANCHO = 760;

    private const ALTO = 400;

    /**
     * Captura a 2x: el viewport de Chromium sigue siendo 760×400 CSS px
     * (mismo layout/tamaños de fuente que ve `opcionesApex()`), pero el PNG
     * resultante tiene el doble de píxeles físicos (1520×800) — mismo
     * principio que una pantalla "retina". `pintarGraficaExcel()` sigue
     * incrustando la imagen al tamaño visual de siempre (608×320), así que
     * el resultado se ve nítido en vez de suavizado/borroso tanto en Excel
     * como en Numbers.
     */
    private const ESCALA = 2;

    /**
     * Devuelve la ruta absoluta de un PNG temporal con la gráfica renderizada,
     * o `null` si Chromium no está disponible o falla — el Excel se genera
     * igual, sólo omite esa imagen (nunca un 500 por un chart que no carga).
     */
    public function generarPng(SerieGraficaReporte $grafica): ?string
    {
        try {
            $html = View::make('reportes._grafica-standalone', [
                'grafica' => $grafica,
                'ancho' => self::ANCHO,
                'alto' => self::ALTO,
            ])->render();

            $ruta = tempnam(sys_get_temp_dir(), 'grafica_').'.png';

            $this->navegador($html)
                ->windowSize(self::ANCHO, self::ALTO)
                ->deviceScaleFactor(self::ESCALA)
                ->waitForFunction('window.__graficaLista === true', null, 15000)
                ->save($ruta);

            if (! file_exists($ruta) || filesize($ruta) === 0) {
                return null;
            }

            register_shutdown_function(static function () use ($ruta): void {
                if (is_file($ruta)) {
                    @unlink($ruta);
                }
            });

            return $ruta;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Misma configuración de binarios que `Spatie\LaravelPdf\Drivers\BrowsershotDriver`
     * (node/npm/chrome/no_sandbox…) para que esta imagen se genere con el
     * MISMO Chromium ya configurado para los PDF ejecutivos — nunca una ruta
     * o binario distinto.
     */
    private function navegador(string $html): Browsershot
    {
        $browsershot = Browsershot::html($html)->showBackground();

        $config = (array) config('laravel-pdf.browsershot', []);

        if ($nodeBinary = ($config['node_binary'] ?? null)) {
            $browsershot->setNodeBinary($nodeBinary);
        }

        if ($npmBinary = ($config['npm_binary'] ?? null)) {
            $browsershot->setNpmBinary($npmBinary);
        }

        if ($includePath = ($config['include_path'] ?? null)) {
            $browsershot->setIncludePath($includePath);
        }

        if ($chromePath = ($config['chrome_path'] ?? null)) {
            $browsershot->setChromePath($chromePath);
        }

        if ($nodeModulesPath = ($config['node_modules_path'] ?? null)) {
            $browsershot->setNodeModulePath($nodeModulesPath);
        }

        if ($binPath = ($config['bin_path'] ?? null)) {
            $browsershot->setBinPath($binPath);
        }

        if ($tempPath = ($config['temp_path'] ?? null)) {
            $browsershot->setCustomTempPath($tempPath);
        }

        if ($config['write_options_to_file'] ?? false) {
            $browsershot->writeOptionsToFile();
        }

        if ($config['no_sandbox'] ?? false) {
            $browsershot->noSandbox();
        }

        return $browsershot;
    }
}
