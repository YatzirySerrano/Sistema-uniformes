<?php

namespace App\Http\Controllers;

use App\Acciones\ConfirmarAcuseTraspaso;
use App\Models\AcuseTraspaso;
use App\Servicios\ServicioAcuseTraspasoPdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Comprobante PDF y firma del acuse de traspaso — análogo a
 * `AcuseController`/`AcuseDevolucionController`. Documento privado: nunca se
 * sirve la ruta física directamente, siempre en streaming tras validar la
 * Policy (acceso a AMBAS empresas del traspaso).
 */
class AcuseTraspasoController extends Controller
{
    public function pdf(AcuseTraspaso $acuse, ServicioAcuseTraspasoPdf $pdf): HttpResponse
    {
        $this->authorize('verPdf', $acuse);

        $contenido = $pdf->contenido($acuse);

        if ($contenido === null) {
            $ruta = $pdf->generar($acuse);
            $acuse->update(['ruta_pdf' => $ruta]);
            $contenido = $pdf->contenido($acuse);
        }

        abort_if($contenido === null, 404, 'El comprobante no está disponible.');

        return response($contenido, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$acuse->nombreArchivoDescarga().'"',
        ]);
    }

    public function firma(AcuseTraspaso $acuse): StreamedResponse
    {
        $this->authorize('verFirma', $acuse);

        abort_unless(Storage::disk('local')->exists($acuse->ruta_firma), 404);

        return Storage::disk('local')->response($acuse->ruta_firma, 'firma-'.$acuse->traspaso->folio.'.png', [
            'Content-Type' => 'image/png',
        ]);
    }

    public function regenerarPdf(AcuseTraspaso $acuse, ConfirmarAcuseTraspaso $accion): RedirectResponse
    {
        $this->authorize('verPdf', $acuse);

        $accion->regenerarPdf($acuse);

        return back()->with('toast', ['type' => 'success', 'message' => 'Comprobante regenerado.']);
    }
}
