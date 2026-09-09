<?php

namespace App\Http\Requests\InventarioFisico;

use App\Models\InventarioFisico;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Registro de un escaneo (o de la entrada manual del código) dentro de una
 * ronda. La autorización revalida el permiso Y el acceso a la empresa de la
 * ronda (`InventarioFisicoPolicy::administrar`). El `codigo` es texto libre: el
 * backend decide si es un `public_token`, una URL del sistema o un código de
 * unidad — nunca el frontend.
 */
class EscanearUnidadRequest extends FormRequest
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
            'codigo' => ['required', 'string', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'codigo.required' => 'Escanea un código QR o escribe el código de la unidad.',
        ];
    }
}
