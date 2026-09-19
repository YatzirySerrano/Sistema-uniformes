<?php

namespace App\Http\Requests\Activos;

use App\Enums\CondicionUnidadActivo;
use App\Models\UnidadActivo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Marcar como dañada una unidad Funcionando (nunca pérdida/robo, eso es
 * `MarcarIncidenciaUnidadRequest`). No pide almacén: la unidad no sale del
 * que ya tiene.
 */
class MarcarCondicionUnidadRequest extends FormRequest
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
                Rule::enum(CondicionUnidadActivo::class)->only([CondicionUnidadActivo::EnReparacion, CondicionUnidadActivo::Inservible]),
            ],
            'motivo' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'condicion_resultante.required' => 'Indica si queda en reparación o inservible.',
            'motivo.required' => 'Indica el motivo del daño.',
        ];
    }
}
