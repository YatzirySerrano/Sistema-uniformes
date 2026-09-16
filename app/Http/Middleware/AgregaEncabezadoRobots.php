<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Este sistema es una aplicación privada autenticada, nunca una página de
 * marketing: ningún response (HTML, PDF, Excel, JSON) debe indexarse. El
 * `<meta name="robots">` de `app.blade.php` cubre las páginas Inertia; este
 * header cubre TODAS las respuestas del grupo `web` (login, verificación de
 * correo, descargas de reportes, etc.), incluso las que no renderizan esa
 * plantilla.
 */
class AgregaEncabezadoRobots
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
