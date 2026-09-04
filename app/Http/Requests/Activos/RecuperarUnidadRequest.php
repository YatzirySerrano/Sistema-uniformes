<?php

namespace App\Http\Requests\Activos;

use App\Enums\CondicionUnidadActivo;
use App\Models\UnidadActivo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Recuperación explícita de una unidad Perdida/Robada. El almacén destino debe
 * abastecer a la empresa de la unidad (validado también en
 * `App\Acciones\RecuperarUnidadActivo`, aquí sólo se acota la búsqueda).
 */
class RecuperarUnidadRequest extends FormRequest
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
        /** @var UnidadActivo $unidad */
        $unidad = $this->route('unidad');

        return [
            'almacen_id' => [
                'required', 'integer',
                Rule::exists('almacen_empresa', 'almacen_id')->where(fn ($q) => $q->where('empresa_id', $unidad->empresa_id)),
            ],
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
            'almacen_id.required' => 'Selecciona el almacén de destino.',
            'almacen_id.exists' => 'Ese almacén no abastece a la empresa de esta unidad.',
            'condicion_resultante.required' => 'Indica la condición con la que regresa la unidad.',
        ];
    }
}
