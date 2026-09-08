<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Verificación de correo por enlace, SIN exigir sesión iniciada.
 *
 * En este sistema un administrador crea la cuenta de otra persona; esa
 * persona recibe el correo y debe poder verificarlo desde el enlace, sin
 * estar autenticada (y sin que una sesión de administrador abierta en el
 * mismo navegador rompa el flujo — que era la causa del 403 «This action is
 * unauthorized» de `Laravel\Fortify\Http\Requests\VerifyEmailRequest`, cuyo
 * `authorize()` comparaba el id del usuario autenticado contra el `{id}` de
 * la ruta).
 *
 * La ruta sigue protegida por `signed` (firma + expiración) y por un
 * throttle. Aquí sólo se revalida el hash del correo actual y se marca
 * `email_verified_at`. Nunca se inicia sesión automáticamente ni se toca la
 * sesión activa.
 */
class VerificarCorreoController extends Controller
{
    public function __invoke(Request $request, string $id, string $hash): RedirectResponse
    {
        $usuario = User::query()->find($id);

        // Enlace inválido: usuario inexistente o el correo cambió desde que se
        // envió (el hash ya no corresponde). No se revela cuál de las dos.
        if ($usuario === null
            || ! hash_equals(sha1($usuario->getEmailForVerification()), (string) $hash)) {
            return redirect()->route('login')->with(
                'status',
                'Este enlace de verificación ya no es válido. Inicia sesión para solicitar uno nuevo.',
            );
        }

        if ($usuario->hasVerifiedEmail()) {
            return $this->redirigir(
                $request,
                $usuario,
                'Tu correo electrónico ya había sido verificado.',
            );
        }

        if ($usuario->markEmailAsVerified()) {
            event(new Verified($usuario));
        }

        return $this->redirigir(
            $request,
            $usuario,
            'Tu correo electrónico fue verificado correctamente. Ya puedes iniciar sesión.',
        );
    }

    /**
     * Redirige tras verificar SIN alterar la sesión: si quien abrió el enlace
     * ya está autenticado como ese mismo usuario, va a su destino habitual;
     * en cualquier otro caso (sin sesión, o sesión de otra persona), va al
     * login con el mensaje. Nunca se hace `Auth::login()` del usuario
     * verificado.
     */
    private function redirigir(Request $request, User $usuario, string $mensaje): RedirectResponse
    {
        if ($request->user()?->getKey() === $usuario->getKey()) {
            return redirect()->intended(config('fortify.home', '/dashboard'))
                ->with('toast', ['type' => 'success', 'message' => $mensaje]);
        }

        return redirect()->route('login')->with('status', $mensaje);
    }
}
