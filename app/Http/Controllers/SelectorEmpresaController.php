<?php

namespace App\Http\Controllers;

use App\Soporte\ContextoEmpresa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SelectorEmpresaController extends Controller
{
    /**
     * Cambia la empresa activa del usuario. Al cambiar se limpian la sucursal
     * seleccionada y los filtros del tenant anterior guardados en sesión.
     */
    public function update(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'empresa_id' => ['required', 'integer'],
        ]);

        /** @var ContextoEmpresa $contexto */
        $contexto = app(ContextoEmpresa::class);

        $autorizada = $contexto->empresasAutorizadas()->firstWhere('id', (int) $datos['empresa_id']);

        if ($autorizada === null) {
            throw ValidationException::withMessages([
                'empresa_id' => 'No tienes acceso a la empresa seleccionada.',
            ]);
        }

        $request->session()->put(ContextoEmpresa::SESSION_KEY, $autorizada->getKey());
        $request->session()->forget(['sucursal_activa_id', 'filtros']);

        return back(fallback: route('dashboard'));
    }
}
