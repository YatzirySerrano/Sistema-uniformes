<?php

namespace App\Servicios;

use App\Models\AcuseDevolucion;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Materializa el comprobante PDF del acuse de devolución a partir del
 * snapshot inmutable. Nunca usa datos actuales de empresa/activo/colaborador.
 */
class ServicioAcuseDevolucionPdf
{
    private const DISCO = 'local';

    /**
     * Genera (o regenera) el PDF y devuelve su ruta en el disco privado.
     */
    public function generar(AcuseDevolucion $acuse): string
    {
        $snapshot = $acuse->snapshot_devolucion;

        $firmaDataUri = null;
        if (Storage::disk(self::DISCO)->exists($acuse->ruta_firma)) {
            $contenido = Storage::disk(self::DISCO)->get($acuse->ruta_firma);
            $firmaDataUri = 'data:image/png;base64,'.base64_encode($contenido);
        }

        $firmaOperadorDataUri = null;
        if ($acuse->ruta_firma_operador !== null && Storage::disk(self::DISCO)->exists($acuse->ruta_firma_operador)) {
            $contenidoOperador = Storage::disk(self::DISCO)->get($acuse->ruta_firma_operador);
            $firmaOperadorDataUri = 'data:image/png;base64,'.base64_encode($contenidoOperador);
        }

        $logoDataUri = null;
        $logoRuta = $snapshot['empresa']['logo_ruta'] ?? null;
        if ($logoRuta !== null && Storage::disk('public')->exists($logoRuta)) {
            $logoDataUri = 'data:'.Storage::disk('public')->mimeType($logoRuta).';base64,'
                .base64_encode(Storage::disk('public')->get($logoRuta));
        }

        $pdf = Pdf::loadView('acuses.comprobante-devolucion', [
            'acuse' => $acuse,
            'snapshot' => $snapshot,
            'firmaDataUri' => $firmaDataUri,
            'firmaOperadorDataUri' => $firmaOperadorDataUri,
            'logoDataUri' => $logoDataUri,
        ])->setPaper('letter');

        $ruta = $acuse->ruta_pdf ?? sprintf('acuses/%d/%s.pdf', $acuse->empresa_id, Str::uuid());

        Storage::disk(self::DISCO)->put($ruta, $pdf->output());

        return $ruta;
    }

    public function contenido(AcuseDevolucion $acuse): ?string
    {
        if ($acuse->ruta_pdf === null || ! Storage::disk(self::DISCO)->exists($acuse->ruta_pdf)) {
            return null;
        }

        return Storage::disk(self::DISCO)->get($acuse->ruta_pdf);
    }
}
