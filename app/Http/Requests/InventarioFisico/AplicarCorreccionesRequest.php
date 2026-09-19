<?php

namespace App\Http\Requests\InventarioFisico;

use App\Models\InventarioFisico;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Aplica en bloque las diferencias verificadas de una ronda. No hay campos
 * que capturar (todo-o-nada, sin selección parcial en esta versión): la
 * autorización revalida el permiso de ajuste de inventario Y el acceso a la
 * empresa/almacén concretos de la ronda (`InventarioFisicoPolicy::aplicarCorrecciones`).
 * El resto de las reglas de negocio (ronda finalizada, con firma, no aplicada
 * dos veces, saldos sin cambios desde el snapshot) las revalida
 * `App\Acciones\AplicarCorreccionesInventarioFisico` bajo lock — nunca se
 * confía en lo que el frontend cree que ve.
 */
class AplicarCorreccionesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ronda = $this->route('inventarioFisico');

        return $ronda instanceof InventarioFisico
            && ($this->user()?->can('aplicarCorrecciones', $ronda) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
