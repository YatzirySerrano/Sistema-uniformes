<?php

namespace App\Http\Requests\Entregas;

use App\Models\EntregaUniforme;
use App\Soporte\ContextoEmpresa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        $empresaId = app(ContextoEmpresa::class)->empresaObligatoria()->getKey();

        return [
            'sucursal_id' => [
                'required', 'integer',
                Rule::exists('sucursales', 'id')->where(fn ($q) => $q->where('empresa_id', $empresaId)->where('activa', true)),
            ],
            'colaborador_id' => [
                'required', 'integer',
                Rule::exists('colaboradores', 'id')->where(fn ($q) => $q->where('empresa_id', $empresaId)->where('activo', true)),
            ],
            'fecha_entrega' => ['required', 'date', 'before_or_equal:today'],
            'notas' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.activo_id' => ['required', 'integer', Rule::exists('activos', 'id')->where(fn ($q) => $q->where('empresa_id', $empresaId))],
            'items.*.talla_id' => ['required', 'integer', Rule::exists('tallas', 'id')->where(fn ($q) => $q->where('empresa_id', $empresaId))],
            'items.*.cantidad' => ['required', 'integer', 'min:1', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Agrega al menos un activo a la entrega.',
            'fecha_entrega.before_or_equal' => 'La fecha de entrega no puede ser futura.',
            'colaborador_id.exists' => 'El colaborador seleccionado no es válido para esta empresa.',
            'sucursal_id.exists' => 'La sucursal seleccionada no es válida para esta empresa.',
        ];
    }

    public function attributes(): array
    {
        return ['items.*.cantidad' => 'cantidad', 'items.*.activo_id' => 'activo', 'items.*.talla_id' => 'talla'];
    }
}
