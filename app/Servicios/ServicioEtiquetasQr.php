<?php

namespace App\Servicios;

use App\Models\UnidadActivo;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\URL;

/**
 * Genera el QR de una unidad de seguimiento individual como PNG embebible
 * (data URI) para las etiquetas en PDF. El QR apunta siempre a la URL pública
 * y permanente basada en `public_token` — nunca al id incremental — y no
 * expira ni se regenera: es la misma URL mientras la unidad exista. Usa
 * `BaconQrCode` con el renderer de GD (ya viene vendored vía Fortify para el
 * QR de 2FA; GD está disponible, Imagick no) — cero dependencias nuevas.
 */
class ServicioEtiquetasQr
{
    public function urlPublica(UnidadActivo $unidad): string
    {
        return URL::to("/activos/unidades/{$unidad->public_token}");
    }

    public function pngDataUri(UnidadActivo $unidad, int $tamano = 220): string
    {
        $writer = new Writer(new GDLibRenderer($tamano, 1));
        $png = $writer->writeString($this->urlPublica($unidad));

        return 'data:image/png;base64,'.base64_encode($png);
    }
}
