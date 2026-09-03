<?php

namespace App\Http\Requests\Activos;

use App\Http\Requests\Concerns\NormalizaEntrada;
use App\Models\CategoriaActivo;
use App\Soporte\ContextoEmpresa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta / edición de una categoría de activo. La empresa la fija el contexto. El
 * `tipo_activo_id` es opcional y, si se envía, debe pertenecer a la empresa.
 */
class GuardarCategoriaActivoRequest extends FormRequest
{
    use NormalizaEntrada;

    public function authorize(): bool
    {
        $categoria = $this->route('categoria');

        return $this->user()?->can('administrar', $categoria instanceof CategoriaActivo ? $categoria : CategoriaActivo::class) ?? false;
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
        $categoria = $this->route('categoria');
        $categoriaId = $categoria instanceof CategoriaActivo ? $categoria->getKey() : null;

        return [
            'nombre' => [
                'required', 'string', 'max:120',
                Rule::unique('categorias_activo', 'nombre')
                    ->where(fn ($q) => $q->where('empresa_id', $empresaId))
                    ->ignore($categoriaId),
            ],
            'tipo_activo_id' => [
                'nullable', 'integer',
                Rule::exists('tipos_activo', 'id')->where(fn ($q) => $q->where('empresa_id', $empresaId)),
            ],
            'activa' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre de la categoría es obligatorio.',
            'nombre.unique' => 'Ya existe una categoría con ese nombre en esta empresa.',
            'tipo_activo_id.exists' => 'El tipo de activo seleccionado no pertenece a esta empresa.',
        ];
    }
}
