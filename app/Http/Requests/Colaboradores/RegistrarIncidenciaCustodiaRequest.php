<?php

namespace App\Http\Requests\Colaboradores;

use App\Http\Requests\Concerns\NormalizaEntrada;
use App\Models\Colaborador;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reporta robo/pérdida de un artículo POR CANTIDAD bajo custodia de un
 * colaborador. La pertenencia real del renglón (misma entrega, mismo
 * colaborador, sólo cantidad — nunca unidad identificada, entrega firmada)
 * la revalida `App\Acciones\RegistrarIncidenciaCustodia` bajo lock; aquí sólo
 * se valida forma y el permiso.
 */
class RegistrarIncidenciaCustodiaRequest extends FormRequest
{
    use NormalizaEntrada;

    public function authorize(): bool
    {
        $colaborador = $this->route('colaborador');

        return $colaborador instanceof Colaborador
            && ($this->user()?->can('reportarIncidenciaCustodia', $colaborador) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'motivo' => $this->limpiar($this->input('motivo')),
            'observacion' => $this->limpiar($this->input('observacion')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'detalle_entrega_id' => ['required', 'integer', Rule::exists('detalles_entrega', 'id')],
            'tipo' => ['required', Rule::in(['robado', 'perdido'])],
            'cantidad' => ['required', 'integer', 'min:1', 'max:100000'],
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
            'detalle_entrega_id.required' => 'Selecciona el renglón de la entrega.',
            'detalle_entrega_id.exists' => 'Ese renglón no existe.',
            'tipo.required' => 'Indica si fue robo o pérdida.',
            'cantidad.required' => 'Indica cuántas piezas se reportan.',
            'cantidad.min' => 'La cantidad debe ser al menos 1.',
            'motivo.required' => 'El motivo es obligatorio.',
        ];
    }
}
