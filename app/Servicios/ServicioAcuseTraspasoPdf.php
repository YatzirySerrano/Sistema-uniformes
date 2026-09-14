<?php

namespace App\Servicios;

use App\Models\AcuseTraspaso;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Materializa el comprobante PDF del acuse de traspaso a partir del snapshot
 * inmutable. Nunca reconstruye con datos maestros actuales (nombres de
 * empresa/almacén/activo pueden cambiar después de firmado).
 */
class ServicioAcuseTraspasoPdf
{
    private const DISCO = 'local';

    /**
     * Genera (o regenera) el PDF y devuelve su ruta en el disco privado.
     */
    public function generar(AcuseTraspaso $acuse): string
    {
        $snapshot = $acuse->snapshot_traspaso;

        $firmaDataUri = null;
        if (Storage::disk(self::DISCO)->exists($acuse->ruta_firma)) {
            $firmaDataUri = 'data:image/png;base64,'.base64_encode(Storage::disk(self::DISCO)->get($acuse->ruta_firma));
        }

        $pdf = Pdf::loadView('acuses.comprobante-traspaso', [
            'acuse' => $acuse,
            'snapshot' => $snapshot,
            'firmaDataUri' => $firmaDataUri,
        ])->setPaper('letter');

        $ruta = $acuse->ruta_pdf ?? sprintf('acuses/traspasos/%d/%s.pdf', $acuse->empresa_origen_id, Str::uuid());

        Storage::disk(self::DISCO)->put($ruta, $pdf->output());

        return $ruta;
    }

    public function contenido(AcuseTraspaso $acuse): ?string
    {
        if ($acuse->ruta_pdf === null || ! Storage::disk(self::DISCO)->exists($acuse->ruta_pdf)) {
            return null;
        }

        return Storage::disk(self::DISCO)->get($acuse->ruta_pdf);
    }
}
