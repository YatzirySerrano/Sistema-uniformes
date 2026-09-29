<?php

namespace App\Http\Controllers;

use App\Models\Colaborador;
use App\Servicios\ServicioCuentaColaborador;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Cuenta de acceso asociada" en la ficha del colaborador: se administra aquí
 * (y no en Usuarios) porque la custodia, el servicio, la empresa y los
 * activos pertenecen al colaborador. Ver y administrar son permisos
 * distintos (`colaboradores.usuario-ver` / `colaboradores.usuario-administrar`).
 */
class CuentaColaboradorController extends Controller
{
    /**
     * Cuentas que pueden vincularse (sólo nombre y correo).
     */
    public function disponibles(Request $request, Colaborador $colaborador, ServicioCuentaColaborador $cuentas): JsonResponse
    {
        $this->authorize('administrarCuenta', $colaborador);

        return response()->json([
            'cuentas' => $cuentas->elegibles($colaborador, $request->user(), trim((string) $request->query('q', ''))),
        ]);
    }

    public function actualizar(Request $request, Colaborador $colaborador, ServicioCuentaColaborador $cuentas): RedirectResponse
    {
        $this->authorize('administrarCuenta', $colaborador);

        $datos = $request->validate(
            ['usuario_id' => ['nullable', 'integer']],
            ['usuario_id.integer' => 'Selecciona una cuenta válida.'],
        );

        $usuarioId = isset($datos['usuario_id']) ? (int) $datos['usuario_id'] : null;
        $cuentas->vincular($colaborador, $usuarioId, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => $usuarioId === null ? 'Cuenta desvinculada.' : 'Cuenta de acceso vinculada.',
        ]);
    }
}
