<?php

namespace App\Http\Requests\Activos;

use App\Http\Requests\Concerns\NormalizaEntrada;
use App\Models\TipoActivo;
use App\Soporte\ContextoEmpresa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta / edición de un tipo de activo. La empresa la fija el contexto; el
 * frontend nunca la envía. El nombre es único (sin distinguir mayúsculas) por
 * empresa.
 */
class GuardarTipoActivoRequest extends FormRequest
{
    use NormalizaEntrada;

    public function authorize(): bool
    {
        $tipo = $this->route('tipo');

        return $this->user()?->can('administrar', $tipo instanceof TipoActivo ? $tipo : TipoActivo::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['nombre' => $this->limpiar($this->input('nombre'))]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $empresaId = app(ContextoEmpresa::class)->empresaObligatoria()->getKey();
        $tipo = $this->route('tipo');
        $tipoId = $tipo instanceof TipoActivo ? $tipo->getKey() : null;

        return [
            'nombre' => [
                'required', 'string', 'max:120',
                Rule::unique('tipos_activo', 'nombre')
                    ->where(fn ($q) => $q->where('empresa_id', $empresaId))
                    ->ignore($tipoId),
            ],
            'activo' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre del tipo de activo es obligatorio.',
            'nombre.unique' => 'Ya existe un tipo de activo con ese nombre en esta empresa.',
        ];
    }
}
