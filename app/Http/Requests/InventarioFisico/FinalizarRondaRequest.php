<?php

namespace App\Http\Requests\InventarioFisico;

use App\Models\InventarioFisico;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cierre de una ronda de inventario físico. Exige la firma manuscrita de quien
 * la realizó y su aceptación explícita de responsabilidad. La autorización
 * revalida el permiso Y el acceso a la empresa de la ronda
 * (`InventarioFisicoPolicy::administrar`).
 */
class FinalizarRondaRequest extends FormRequest
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
            'firma' => ['required', 'string', 'max:3000000'],
            'aceptacion' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'firma.required' => 'Firma la ronda antes de cerrarla.',
            'aceptacion.accepted' => 'Debes aceptar la responsabilidad de lo registrado antes de cerrar la ronda.',
        ];
    }
}
