<?php

namespace App\Http\Responses\Fortify;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;

/**
 * Mensaje GENÉRICO de "enlace de recuperación enviado", igual para todos los
 * correos (existan o no en el sistema) — ver `SolicitudRecuperacionFallidaResponse`,
 * que reutiliza este mismo mensaje cuando el correo no existe, para no
 * revelar por enumeración qué correos tienen cuenta.
 */
class SolicitudRecuperacionExitosaResponse implements SuccessfulPasswordResetLinkRequestResponse
{
    public const MENSAJE = 'Si existe una cuenta asociada a ese correo, recibirás un enlace de recuperación.';

    public function __construct(protected string $status) {}

    public function toResponse($request)
    {
        return $request->wantsJson()
            ? new JsonResponse(['message' => self::MENSAJE], 200)
            : back()->with('status', self::MENSAJE);
    }
}
