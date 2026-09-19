<?php

namespace App\Http\Controllers\Concerns;

use App\Exports\ListadoExport;
use App\Soporte\ContextoExportacion;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\LaravelPdf\Enums\Format;
use Spatie\LaravelPdf\Facades\Pdf;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Exportación Excel/PDF de un listado ya resuelto a filas planas — mismas
 * filas que ve la pantalla (`?formato=xlsx` por defecto, `?formato=pdf`).
 * Cada controller construye sus propias `$filas`/`$encabezados` desde LA
 * MISMA consulta filtrada que usa su `index()` (nunca una consulta aparte)
 * y un `ContextoExportacion` (empresa/filtros humanizados/total/KPIs/
 * gráficas), y delega aquí sólo el mecanismo de descarga + identidad visual
 * compartida.
 *
 * El PDF de los reportes ejecutivos usa `spatie/laravel-pdf` con el driver
 * Browsershot (Chromium real: CSS Grid/Flexbox, gráficas ApexCharts vivas)
 * — NUNCA DomPDF, que se conserva exclusivamente para acuses/documentos
 * firmados (`ServicioAcusePdf`, `resources/views/acuses/*`), sin tocar.
 */
trait ExportaListado
{
    /**
     * @param  array<int, array<int, string|int|null>>  $filas
     * @param  array<int, string>  $encabezados
     */
    protected function respuestaExportacion(string $formato, array $filas, array $encabezados, ContextoExportacion $contexto): BinaryFileResponse|HttpResponse
    {
        $nombreArchivo = $contexto->nombreArchivo();

        if ($formato === 'pdf') {
            $reporte = Pdf::view('reportes.listado-generico', [
                'contexto' => $contexto,
                'encabezados' => $encabezados,
                'filas' => $filas,
            ])
                ->format(Format::Letter)
                ->landscape()
                ->margins(10, 10, 16, 10)
                ->footerView('reportes._pie');

            if ($contexto->graficas !== []) {
                $reporte->waitUntilReady();
            }

            return response($reporte->generatePdfContent(), 200, [
                'Content-Type' => 'application/pdf',
                // inline: el usuario ve el PDF antes de decidir si lo
                // descarga/imprime (el navegador lo abre en una pestaña);
                // nunca fuerza la descarga automática.
                'Content-Disposition' => 'inline; filename="'.$nombreArchivo.'.pdf"',
            ]);
        }

        return Excel::download(new ListadoExport($filas, $encabezados, $contexto), $nombreArchivo.'.xlsx');
    }
}
