<?php

namespace App\Http\Controllers;

use App\Acciones\ConfirmarAcuseDevolucion;
use App\Enums\EstadoDevolucion;
use App\Models\AcuseDevolucion;
use App\Models\DetalleDevolucion;
use App\Models\Devolucion;
use App\Models\Evidencia;
use App\Servicios\ServicioAcuseDevolucionPdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Firma de doble conformidad de una devolución (quien devuelve + encargado
 * que recibe), análogo a `AcuseController` para Entregas.
 */
class AcuseDevolucionController extends Controller
{
    public function firmar(Devolucion $devolucion): Response|RedirectResponse
    {
        $this->authorize('confirmar', $devolucion);

        if ($devolucion->estado !== EstadoDevolucion::PendienteFirma) {
            return to_route('devoluciones.index')->with('toast', [
                'type' => 'info', 'message' => 'Esta devolución ya fue confirmada.',
            ]);
        }

        $devolucion->load(['detalles.activo', 'detalles.talla', 'detalles.evidencias:id,evidenciable_id,evidenciable_type,mime,origen', 'colaborador:id,nombre_completo,numero_empleado', 'sucursal:id,nombre', 'registradaPor:id,name', 'empresa:id,nombre_comercial']);

        return Inertia::render('Devoluciones/Firmar', [
            'devolucion' => [
                'id' => $devolucion->id,
                'folio' => $devolucion->folio,
                'fecha' => $devolucion->fecha->toDateString(),
                'motivo' => $devolucion->motivo,
                'empresa' => $devolucion->empresa?->nombre_comercial,
                'sucursal' => $devolucion->sucursal?->nombre,
                'operador' => $devolucion->registradaPor?->name,
                'colaborador' => $devolucion->colaborador?->only(['nombre_completo', 'numero_empleado']),
                'items' => $devolucion->detalles->map(fn (DetalleDevolucion $d): array => [
                    'activo' => $d->activo?->nombre,
                    'talla' => $d->talla?->valor,
                    'cantidad' => $d->cantidad,
                    'evidencias' => $d->evidencias->map(fn (Evidencia $e): array => [
                        'url' => route('devoluciones.evidencias.ver', $e),
                        'mime' => $e->mime,
                    ])->all(),
                ]),
            ],
        ]);
    }

    public function confirmar(Request $request, Devolucion $devolucion, ConfirmarAcuseDevolucion $accion): RedirectResponse
    {
        $this->authorize('confirmar', $devolucion);

        $datos = $request->validate([
            'firma' => ['required', 'string', 'max:3000000'],
            'firma_operador' => ['required', 'string', 'max:3000000'],
            'aceptacion' => ['accepted'],
        ], [
            'firma.required' => 'La firma de quien devuelve es obligatoria.',
            'firma_operador.required' => 'La firma de quien recibe es obligatoria.',
            'aceptacion.accepted' => 'Debes confirmar que aceptas la responsabilidad antes de firmar.',
        ]);

        $acuse = $accion->ejecutar(
            $devolucion,
            $datos['firma'],
            $datos['firma_operador'],
            true,
            $request->user()->id,
            $request->ip(),
            $request->userAgent(),
        );

        $mensaje = $acuse->tienePdf()
            ? "Devolución confirmada. Acuse {$acuse->folio} generado."
            : "Devolución confirmada. El comprobante PDF se generará en breve (acuse {$acuse->folio}).";

        return to_route('devoluciones.index')->with('toast', ['type' => 'success', 'message' => $mensaje]);
    }

    public function pdf(AcuseDevolucion $acuse, ServicioAcuseDevolucionPdf $pdf): HttpResponse
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

    public function firma(AcuseDevolucion $acuse): StreamedResponse
    {
        $this->authorize('verFirma', $acuse);

        abort_unless(Storage::disk('local')->exists($acuse->ruta_firma), 404);

        return Storage::disk('local')->response($acuse->ruta_firma, 'firma-'.$acuse->folio.'.png', [
            'Content-Type' => 'image/png',
        ]);
    }

    public function firmaOperador(AcuseDevolucion $acuse): StreamedResponse
    {
        $this->authorize('verFirma', $acuse);

        abort_unless($acuse->ruta_firma_operador !== null && Storage::disk('local')->exists($acuse->ruta_firma_operador), 404);

        return Storage::disk('local')->response($acuse->ruta_firma_operador, 'firma-encargado-'.$acuse->folio.'.png', [
            'Content-Type' => 'image/png',
        ]);
    }

    public function regenerarPdf(AcuseDevolucion $acuse, ConfirmarAcuseDevolucion $accion): RedirectResponse
    {
        $this->authorize('verPdf', $acuse);

        $accion->regenerarPdf($acuse);

        return back()->with('toast', ['type' => 'success', 'message' => 'Comprobante regenerado.']);
    }
}
