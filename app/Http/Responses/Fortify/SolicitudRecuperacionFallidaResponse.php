<?php

namespace App\Http\Responses\Fortify;

use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;

/**
 * Si el correo no existe (`Password::INVALID_USER`), responde EXACTAMENTE
 * igual que una solicitud exitosa (mismo mensaje, mismo tipo de respuesta)
 * para no revelar por enumeración qué correos tienen cuenta. Cualquier otro
 * motivo de fallo (p. ej. `RESET_THROTTLED`) sí se muestra tal cual —
 * limitar la frecuencia de intentos no es una fuga de identidad.
 */
class SolicitudRecuperacionFallidaResponse implements FailedPasswordResetLinkRequestResponse
{
    public function __construct(protected string $status) {}

    public function toResponse($request)
    {
        if ($this->status === Password::INVALID_USER) {
            return (new SolicitudRecuperacionExitosaResponse($this->status))->toResponse($request);
        }

        if ($request->wantsJson()) {
            throw ValidationException::withMessages([
                'email' => [trans($this->status)],
            ]);
        }

        return back()
            ->withInput($request->only('email'))
            ->withErrors(['email' => trans($this->status)]);
    }
}
