<?php

namespace App\Http\Requests\InventarioFisico;

use App\Http\Requests\Concerns\ResuelveEmpresa;
use App\Models\InventarioFisico;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de una ronda de inventario físico. La empresa llega en `empresa_id` y
 * se valida contra el alcance del usuario (`ResuelveEmpresa`); nunca se confía
 * en el frontend. El almacén es un alcance OPCIONAL y, si viene, debe abastecer
 * a esa empresa.
 */
class GuardarInventarioFisicoRequest extends FormRequest
{
    use ResuelveEmpresa;

    public function authorize(): bool
    {
        return $this->user()?->can('create', InventarioFisico::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $empresaId = $this->empresaResuelta()->getKey();

        return [
            'empresa_id' => ['required', 'integer'],
            'nombre' => ['required', 'string', 'max:255'],
            'almacen_id' => [
                'nullable', 'integer',
                Rule::exists('almacen_empresa', 'almacen_id')->where('empresa_id', $empresaId),
            ],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'empresa_id.required' => 'Selecciona la empresa de la ronda.',
            'nombre.required' => 'Ponle un nombre a la ronda (por ejemplo «Inventario diciembre 2026 – DASTI»).',
            'almacen_id.exists' => 'El almacén seleccionado no abastece a esta empresa.',
        ];
    }
}
