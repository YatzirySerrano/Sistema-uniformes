<?php

namespace App\Http\Requests\Activos;

use App\Http\Requests\Concerns\NormalizaEntrada;
use App\Http\Requests\Concerns\ResuelveEmpresa;
use App\Models\CategoriaActivo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Alta / edición de una categoría de activo. En alta la empresa llega en
 * `empresa_id` y se valida el acceso; en edición queda fijada por el registro.
 * El `tipo_activo_id` es opcional y, si se envía, debe pertenecer a la empresa.
 * El nombre es único por empresa sin distinguir mayúsculas ni espacios
 * sobrantes (ver `NombreNormalizado`).
 */
class GuardarCategoriaActivoRequest extends FormRequest
{
    use NormalizaEntrada, ResuelveEmpresa;

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
        $empresaId = $this->empresaResuelta('categoria')->getKey();
        $categoria = $this->route('categoria');
        $categoriaId = $categoria instanceof CategoriaActivo ? $categoria->getKey() : null;

        return [
            ...($categoriaId === null ? ['empresa_id' => ['required', 'integer']] : []),
            'nombre' => ['required', 'string', 'max:120'],
            'tipo_activo_id' => [
                'nullable', 'integer',
                Rule::exists('tipos_activo', 'id')->where(fn ($q) => $q->where('empresa_id', $empresaId)),
            ],
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
            $empresaId = $this->empresaResuelta('categoria')->getKey();

            if (CategoriaActivo::existeNombreEnEmpresa($empresaId, $nombre, $ignorar)) {
                $validator->errors()->add('nombre', 'Ya existe una categoría con ese nombre en esta empresa.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'empresa_id.required' => 'Selecciona la empresa de la categoría.',
            'nombre.required' => 'El nombre de la categoría es obligatorio.',
            'tipo_activo_id.exists' => 'El tipo de activo seleccionado no pertenece a esta empresa.',
        ];
    }
}
