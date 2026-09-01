<?php

namespace App\Excepciones;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;

/**
 * Base de las reglas de negocio incumplibles. Produce una respuesta controlada
 * en español en lugar de un error 500: redirige de vuelta con el mensaje en el
 * saco de errores y como toast.
 */
abstract class ExcepcionDeNegocio extends RuntimeException
{
    public function render(Request $request): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'errors' => ['negocio' => [$this->getMessage()]],
            ], 422);
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => $this->getMessage()]);

        return back()->withErrors(['negocio' => $this->getMessage()])->withInput();
    }
}
