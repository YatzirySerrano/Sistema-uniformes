<?php

namespace App\Http\Controllers\Concerns;

use App\Exports\ListadoExport;
use App\Soporte\ContextoExportacion;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Exportación Excel/PDF de un listado ya resuelto a filas planas — mismas
 * filas que ve la pantalla (`?formato=xlsx` por defecto, `?formato=pdf`).
 * Cada controller construye sus propias `$filas`/`$encabezados` desde LA
 * MISMA consulta filtrada que usa su `index()` (nunca una consulta aparte)
 * y un `ContextoExportacion` (empresa/filtros humanizados/total), y delega
 * aquí sólo el mecanismo de descarga + identidad visual compartida.
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
            $pdf = Pdf::loadView('reportes.listado-generico', [
                'contexto' => $contexto,
                'encabezados' => $encabezados,
                'filas' => $filas,
            ])->setPaper('letter', 'landscape');

            return response($pdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$nombreArchivo.'.pdf"',
            ]);
        }

        return Excel::download(new ListadoExport($filas, $encabezados, $contexto), $nombreArchivo.'.xlsx');
    }
}
