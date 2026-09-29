<?php

use App\Http\Middleware\AgregaEncabezadoRobots;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\VerificarUsuarioActivo;
use App\Soporte\AccesoNoAutorizado;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Inertia\Inertia;
use Inertia\Ssr\SsrState;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            VerificarUsuarioActivo::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            AgregaEncabezadoRobots::class,
        ]);

        $middleware->alias([
            'usuario.activo' => VerificarUsuarioActivo::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Enlace de verificación caducado o manipulado: en vez de un 403
        // «Invalid signature» crudo, se redirige al login con un mensaje
        // claro. La firma se sigue validando; sólo cambia la presentación.
        $exceptions->render(function (InvalidSignatureException $e, Request $request) {
            if ($request->is('email/verify/*') && ! $request->expectsJson()) {
                return redirect()->route('login')->with(
                    'status',
                    'Este enlace de verificación ya no es válido o ha expirado. Inicia sesión para solicitar uno nuevo.',
                );
            }
        });

        // Demasiados intentos seguidos sobre el enlace de verificación: se
        // conserva el límite, pero con un mensaje entendible.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('email/verify*') && ! $request->expectsJson()) {
                return redirect()->route('login')->with(
                    'status',
                    'Hiciste demasiados intentos seguidos. Espera un momento y vuelve a abrir el enlace del correo.',
                );
            }
        });

        // Acceso NO autorizado (403): la autorización NO cambia (Policies,
        // Gates, `abort(403)` siguen respondiendo 403); sólo cambia la
        // PRESENTACIÓN para el usuario final, nunca «This action is
        // unauthorized.» en inglés ni detalles técnicos. Otros códigos (404,
        // 419, 500…) conservan su manejo normal.
        $exceptions->respond(function (SymfonyResponse $response, Throwable $e, Request $request) {
            if ($response->getStatusCode() !== 403) {
                return $response;
            }

            $mensaje = AccesoNoAutorizado::mensajePara($e);

            // fetch/JSON y ACCIONES (formulario, botón, diálogo — cualquier
            // método distinto de GET): 403 real con un JSON manejable en
            // español. El frontend no navega a una página de error: muestra
            // el mensaje como toast (`resources/js/lib/avisoSinPermiso.ts`,
            // tanto para `fetch` como para el evento `httpException` de
            // Inertia).
            if ($request->expectsJson() || ! $request->isMethodSafe()) {
                return response()->json(['message' => $mensaje], 403);
            }

            // Navegación (Inertia o carga directa): página propia con el
            // layout de la app. Sin sesión no hay layout que mostrar.
            if ($request->user() === null) {
                return $response;
            }

            // El <head> SSR se memoriza por petición: la página de error nunca
            // debe reutilizar el de otra página (p. ej. su <title> con datos
            // del recurso negado) si la misma instancia atendió una antes.
            app()->forgetInstance(SsrState::class);

            return Inertia::render('Errores/SinPermiso', ['mensaje' => $mensaje])
                ->toResponse($request)
                ->setStatusCode(403);
        });
    })->create();
