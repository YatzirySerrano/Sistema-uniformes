<?php

namespace App\Http\Middleware;

use App\Models\ConfiguracionSistema;
use App\Soporte\AccesoEmpresa;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * @var string
     */
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $usuario = $request->user();
        $configuracionVisual = ConfiguracionSistema::actual();

        $empresasAutorizadas = $usuario === null
            ? []
            : app(AccesoEmpresa::class)->empresasAutorizadas($usuario)
                ->map(fn ($e): array => [
                    'id' => $e->id,
                    'codigo' => $e->codigo,
                    'nombre_comercial' => $e->nombre_comercial,
                ])->values()->all();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $usuario === null ? null : [
                    'id' => $usuario->id,
                    'name' => $usuario->name,
                    'email' => $usuario->email,
                    'email_verified_at' => $usuario->email_verified_at,
                    'activo' => $usuario->activo,
                    'roles' => $usuario->getRoleNames()->all(),
                    'permisos' => $usuario->getAllPermissions()->pluck('name')->all(),
                    'es_superadministrador' => $usuario->esSuperadministrador(),
                ],
            ],
            // El sistema es multiempresa pero NO tiene "empresa activa": el
            // contexto de empresa se elige en cada formulario / filtro. Esta
            // lista alimenta esos combobox en el cliente.
            'empresasAutorizadas' => $empresasAutorizadas,
            // Personalización visual GLOBAL (nunca por empresa): custom
            // properties CSS aplicadas en el cliente tras cada navegación /
            // guardado, además del <style> ya renderizado por el servidor en
            // app.blade.php para el primer pintado (sin parpadeo).
            'temaVisual' => [
                'claro' => $configuracionVisual->variablesClaro(),
                'oscuro' => $configuracionVisual->variablesOscuro(),
            ],
            // Inertia::always: sin esto, un partial reload (p. ej. cambiar un
            // filtro con `only`) no incluye 'flash' en la respuesta y el
            // cliente conserva el toast anterior en memoria, reapareciendo en
            // cada navegación posterior. pull() lo consume una sola vez para
            // que las siguientes respuestas sobrescriban el prop con null.
            'flash' => Inertia::always([
                'toast' => $request->session()->pull('toast'),
                // URL de un PDF (p. ej. etiquetas QR) a abrir tras una acción
                // Inertia exitosa. Nunca se devuelve el PDF directamente en la
                // respuesta de una petición Inertia (el cliente la renderizaría
                // como si fuera una página) — ver `resources/js/lib/flashEtiquetas.ts`.
                'etiquetasUrl' => $request->session()->pull('etiquetasUrl'),
            ]),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
