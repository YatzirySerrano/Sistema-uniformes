<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\VerificarUsuarioActivo;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;

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
    })->create();
