<?php

namespace App\Http\Requests\Activos;

use App\Enums\CondicionUnidadActivo;
use App\Models\UnidadActivo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MarcarIncidenciaUnidadRequest extends FormRequest
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
            'tipo' => ['required', Rule::enum(CondicionUnidadActivo::class)],
            'motivo' => ['required', 'string', 'max:255'],
            'observacion' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipo.required' => 'Indica si es pérdida o robo.',
            'motivo.required' => 'Indica el motivo de la incidencia.',
        ];
    }
}
