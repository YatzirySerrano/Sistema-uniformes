<?php

namespace App\Http\Middleware;

use App\Soporte\ContextoEmpresa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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

        /** @var ContextoEmpresa $contexto */
        $contexto = app(ContextoEmpresa::class);
        $empresaActiva = $contexto->empresa();

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
            'contextoEmpresa' => $usuario === null ? null : [
                'empresaActivaId' => $contexto->id(),
                'empresaActiva' => $empresaActiva === null ? null : [
                    'id' => $empresaActiva->id,
                    'codigo' => $empresaActiva->codigo,
                    'nombre_comercial' => $empresaActiva->nombre_comercial,
                    'logo_url' => $empresaActiva->logo_ruta ? Storage::disk('public')->url($empresaActiva->logo_ruta) : null,
                ],
                'empresasDisponibles' => $contexto->empresasAutorizadas()
                    ->map(fn ($e): array => ['id' => $e->id, 'codigo' => $e->codigo, 'nombre_comercial' => $e->nombre_comercial])
                    ->values()->all(),
                'sucursalesDisponibles' => $contexto->sucursalesDisponibles()
                    ->map(fn ($s): array => ['id' => $s->id, 'codigo' => $s->codigo, 'nombre' => $s->nombre])
                    ->values()->all(),
                'branding' => $empresaActiva?->tokensDeMarca() ?? [],
            ],
            'flash' => [
                'toast' => $request->session()->get('toast'),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
