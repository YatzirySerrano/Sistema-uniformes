<?php

namespace App\Http\Middleware;

use App\Soporte\ContextoEmpresa;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hidrata el ContextoEmpresa a partir del usuario autenticado y de la empresa
 * guardada en sesión, revalidando siempre el acceso.
 */
class ResolverEmpresaActiva
{
    public function __construct(private readonly ContextoEmpresa $contexto) {}

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        $this->contexto->paraUsuario($usuario);

        if ($usuario !== null) {
            $empresaId = $request->session()->get(ContextoEmpresa::SESSION_KEY);
            $this->contexto->resolverDesde($empresaId !== null ? (int) $empresaId : null);

            // Normaliza la sesión al valor efectivamente resuelto.
            $request->session()->put(ContextoEmpresa::SESSION_KEY, $this->contexto->id());
        }

        return $next($request);
    }
}
