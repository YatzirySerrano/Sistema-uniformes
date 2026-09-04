<?php

namespace App\Http\Requests\Activos;

use App\Http\Requests\Concerns\NormalizaEntrada;
use App\Models\CategoriaActivo;
use App\Soporte\AccesoEmpresa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Alta / edición de una categoría de activo del catálogo compartido. En alta se
 * reciben las `empresa_ids` para las que queda habilitada; en edición sólo se
 * renombra, se re-vincula el tipo (opcional) y se togglea el estado global. El
 * `tipo_activo_id` es opcional y también del catálogo compartido. El nombre es
 * único **a nivel plataforma** (`NombreNormalizado`).
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
        $enEdicion = $this->route('categoria') instanceof CategoriaActivo;
        $idsAutorizadas = app(AccesoEmpresa::class)->idsAutorizados($this->user())->all();

        return [
            ...($enEdicion ? [] : [
                'empresa_ids' => ['required', 'array', 'min:1'],
                'empresa_ids.*' => ['integer', Rule::in($idsAutorizadas)],
            ]),
            'nombre' => ['required', 'string', 'max:120'],
            'tipo_activo_id' => ['nullable', 'integer', Rule::exists('tipos_activo', 'id')],
            'activa' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $nombre = $this->input('nombre');

            if (! is_string($nombre) || trim($nombre) === '') {
                return;
            }

            $categoria = $this->route('categoria');
            $ignorar = $categoria instanceof CategoriaActivo ? $categoria->getKey() : null;

            if (CategoriaActivo::existeNombre($nombre, $ignorar)) {
                $validator->errors()->add('nombre', 'Ya existe una categoría con ese nombre.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'empresa_ids.required' => 'Elige al menos una empresa para la que habilitar la categoría.',
            'empresa_ids.*.in' => 'Una de las empresas seleccionadas está fuera de tu alcance.',
            'nombre.required' => 'El nombre de la categoría es obligatorio.',
            'tipo_activo_id.exists' => 'El tipo de activo seleccionado no existe.',
        ];
    }
}
