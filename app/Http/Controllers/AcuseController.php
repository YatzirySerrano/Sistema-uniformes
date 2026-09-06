<?php

namespace App\Http\Controllers;

use App\Acciones\ConfirmarAcuseRecepcion;
use App\Enums\EstadoEntrega;
use App\Models\AcuseRecepcion;
use App\Models\EntregaUniforme;
use App\Servicios\ServicioAcusePdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AcuseController extends Controller
{
    public function firmar(EntregaUniforme $entrega): Response|RedirectResponse
    {
        $this->authorize('firmar', $entrega);

        if ($entrega->estado !== EstadoEntrega::PendienteFirma) {
            return to_route('entregas.show', $entrega)->with('toast', [
                'type' => 'info', 'message' => 'Esta entrega ya fue firmada.',
            ]);
        }

        $entrega->load(['detalles', 'colaborador:id,nombre_completo,numero_empleado', 'sucursal:id,nombre', 'encargado:id,name', 'empresa:id,nombre_comercial']);

        return Inertia::render('Acuses/Firmar', [
            'entrega' => [
                'id' => $entrega->id,
                'folio' => $entrega->folio,
                'fecha_entrega' => $entrega->fecha_entrega->toDateString(),
                'empresa' => $entrega->empresa?->nombre_comercial,
                'sucursal' => $entrega->sucursal?->nombre,
                'encargado' => $entrega->encargado?->name,
                'colaborador' => $entrega->colaborador?->only(['nombre_completo', 'numero_empleado']),
                'items' => $entrega->detalles->map(fn ($d): array => [
                    'activo' => $d->activo_nombre_snapshot,
                    'talla' => $d->talla_valor_snapshot,
                    'cantidad' => $d->cantidad,
                ]),
            ],
        ]);
    }

    public function confirmar(Request $request, EntregaUniforme $entrega, ConfirmarAcuseRecepcion $accion): RedirectResponse
    {
        $this->authorize('firmar', $entrega);

        $datos = $request->validate([
            'firma' => ['required', 'string', 'max:3000000'],
            'firma_operador' => ['required', 'string', 'max:3000000'],
            'aceptacion' => ['accepted'],
        ], [
            'firma.required' => 'La firma de quien recibe es obligatoria.',
            'firma_operador.required' => 'La firma de quien entrega es obligatoria.',
            'aceptacion.accepted' => 'Debes confirmar que aceptas la responsabilidad antes de firmar.',
        ]);

        $acuse = $accion->ejecutar(
            $entrega,
            $datos['firma'],
            $datos['firma_operador'],
            true,
            $request->user()->id,
            $request->ip(),
            $request->userAgent(),
        );

        $mensaje = $acuse->tienePdf()
            ? "Recepción confirmada. Acuse {$acuse->folio} generado."
            : "Recepción confirmada. El comprobante PDF se generará en breve (acuse {$acuse->folio}).";

        return to_route('entregas.show', $entrega)->with('toast', ['type' => 'success', 'message' => $mensaje]);
    }

    public function pdf(AcuseRecepcion $acuse, ServicioAcusePdf $pdf): HttpResponse
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

    public function firma(AcuseRecepcion $acuse): StreamedResponse
    {
        $this->authorize('verFirma', $acuse);

        abort_unless(Storage::disk('local')->exists($acuse->ruta_firma), 404);

        return Storage::disk('local')->response($acuse->ruta_firma, 'firma-'.$acuse->folio.'.png', [
            'Content-Type' => 'image/png',
        ]);
    }

    public function firmaOperador(AcuseRecepcion $acuse): StreamedResponse
    {
        $this->authorize('verFirma', $acuse);

        abort_unless($acuse->ruta_firma_operador !== null && Storage::disk('local')->exists($acuse->ruta_firma_operador), 404);

        return Storage::disk('local')->response($acuse->ruta_firma_operador, 'firma-encargado-'.$acuse->folio.'.png', [
            'Content-Type' => 'image/png',
        ]);
    }

    public function regenerarPdf(AcuseRecepcion $acuse, ConfirmarAcuseRecepcion $accion): RedirectResponse
    {
        $this->authorize('verPdf', $acuse);

        $accion->regenerarPdf($acuse);

        return back()->with('toast', ['type' => 'success', 'message' => 'Comprobante regenerado.']);
    }
}
