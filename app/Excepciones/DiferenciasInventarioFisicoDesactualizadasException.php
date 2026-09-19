<?php

namespace App\Excepciones;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Aborta TODO el lote de `App\Acciones\AplicarCorreccionesInventarioFisico`
 * porque una o más existencias cambiaron después de tomarse el snapshot de la
 * ronda (entrada, entrega, devolución, ajuste u otra operación posterior).
 * Nunca aplica las combinaciones que sí coincidían: es todo o nada.
 *
 * Además del mensaje genérico (heredado de `ExcepcionDeNegocio`), deja las
 * combinaciones en conflicto en la sesión para que la pantalla las muestre en
 * la siguiente carga — mismo mecanismo que `flash.toast`.
 */
class DiferenciasInventarioFisicoDesactualizadasException extends ExcepcionDeNegocio
{
    /**
     * @param  list<array{activo: string, talla: string|null, esperada: int, actual: int}>  $conflictos
     */
    public function __construct(string $message, public readonly array $conflictos)
    {
        parent::__construct($message);
    }

    public function render(Request $request): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'errors' => ['negocio' => [$this->getMessage()]],
                'conflictos' => $this->conflictos,
            ], 422);
        }

        session()->flash('conflictosInventarioFisico', $this->conflictos);

        return parent::render($request);
    }
}
