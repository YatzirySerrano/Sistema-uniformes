<?php

namespace App\Http\Requests\Entregas;

use App\Models\Colaborador;
use App\Models\EntregaUniforme;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de entrega. La empresa y la sucursal se DERIVAN del colaborador
 * seleccionado; el frontend sólo envía `colaborador_id`. Los activos y tallas
 * deben pertenecer a la empresa de ese colaborador.
 */
class GuardarEntregaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', EntregaUniforme::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $colaborador = Colaborador::query()->find($this->integer('colaborador_id'));
        $empresaId = $colaborador?->empresa_id;

        if ($colaborador === null || ! $this->user()?->puedeAccederEmpresa($empresaId)) {
            return ['colaborador_id' => ['required', 'integer', 'exists:colaboradores,id']];
        }

        return [
            'colaborador_id' => [
                'required', 'integer',
                Rule::exists('colaboradores', 'id')->where(fn ($q) => $q->where('empresa_id', $empresaId)->where('activo', true)),
            ],
            'fecha_entrega' => ['required', 'date', 'before_or_equal:today'],
            'notas' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.activo_id' => ['required', 'integer', Rule::exists('activos', 'id')->where(fn ($q) => $q->where('empresa_id', $empresaId))],
            'items.*.talla_id' => ['required', 'integer', Rule::exists('talla_empresa', 'talla_id')->where(fn ($q) => $q->where('empresa_id', $empresaId))],
            'items.*.cantidad' => ['required', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Agrega al menos un activo a la entrega.',
            'fecha_entrega.before_or_equal' => 'La fecha de entrega no puede ser futura.',
            'colaborador_id.exists' => 'El colaborador seleccionado no es válido o no tienes acceso a su empresa.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['items.*.cantidad' => 'cantidad', 'items.*.activo_id' => 'activo', 'items.*.talla_id' => 'talla'];
    }
}
