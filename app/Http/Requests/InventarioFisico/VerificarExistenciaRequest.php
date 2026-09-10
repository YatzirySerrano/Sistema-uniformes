<?php

namespace App\Http\Requests\InventarioFisico;

use App\Models\InventarioFisico;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Registro de la cantidad CONTADA de un renglón de artículos por cantidad
 * dentro de una ronda. El botón "Coincide" del cliente envía
 * `cantidad_contada = cantidad_esperada`. La autorización revalida el permiso Y
 * el acceso a la empresa de la ronda (`InventarioFisicoPolicy::administrar`).
 */
class VerificarExistenciaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ronda = $this->route('inventarioFisico');

        return $ronda instanceof InventarioFisico
            && ($this->user()?->can('administrar', $ronda) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cantidad_contada' => ['required', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cantidad_contada.required' => 'Indica cuántas unidades contaste.',
            'cantidad_contada.min' => 'La cantidad contada no puede ser negativa.',
        ];
    }
}
