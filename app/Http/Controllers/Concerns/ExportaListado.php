<?php

namespace App\Http\Controllers\Concerns;

use App\Exports\ListadoExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Exportación Excel/PDF de un listado ya resuelto a filas planas — mismas
 * filas que ve la pantalla (`?formato=xlsx` por defecto, `?formato=pdf`).
 * Cada controller construye sus propias `$filas`/`$encabezados` desde LA
 * MISMA consulta filtrada que usa su `index()` (nunca una consulta aparte),
 * y delega aquí sólo el mecanismo de descarga.
 */
trait ExportaListado
{
    /**
     * @param  array<int, array<int, string|int|null>>  $filas
     * @param  array<int, string>  $encabezados
     */
    protected function respuestaExportacion(string $formato, array $filas, array $encabezados, string $titulo): BinaryFileResponse|HttpResponse
    {
        $nombreArchivo = Str::slug($titulo).'-'.now()->toDateString();

        if ($formato === 'pdf') {
            $pdf = Pdf::loadView('reportes.listado-generico', [
                'titulo' => $titulo,
                'encabezados' => $encabezados,
                'filas' => $filas,
            ])->setPaper('letter', 'landscape');

            return response($pdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$nombreArchivo.'.pdf"',
            ]);
        }

        return Excel::download(new ListadoExport($filas, $encabezados, $titulo), $nombreArchivo.'.xlsx');
    }
}
