<?php

namespace App\Http\Requests\Activos;

use App\Enums\CondicionUnidadActivo;
use App\Models\UnidadActivo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Restauración explícita de la condición de una unidad "En reparación" /
 * "Inservible" (nunca pérdida/robo, eso es `RecuperarUnidadRequest`). No pide
 * almacén: la unidad nunca salió del que ya tiene.
 */
class RestaurarCondicionUnidadRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var UnidadActivo $unidad */
        $unidad = $this->route('unidad');

        return $this->user()?->can('administrar', $unidad) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'condicion_resultante' => [
                'required',
                Rule::enum(CondicionUnidadActivo::class)->except([CondicionUnidadActivo::Perdido, CondicionUnidadActivo::Robado]),
            ],
            'notas' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'condicion_resultante.required' => 'Indica la condición con la que queda la unidad.',
        ];
    }
}
