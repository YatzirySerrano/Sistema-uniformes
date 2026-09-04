<?php

namespace App\Http\Requests\Activos;

use App\Http\Requests\Concerns\NormalizaEntrada;
use App\Models\TipoActivo;
use App\Soporte\AccesoEmpresa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Alta / edición de un tipo de activo del catálogo compartido. En alta se
 * reciben las `empresa_ids` para las que queda habilitado; en edición sólo se
 * renombra y se togglea el estado global. El nombre es único **a nivel
 * plataforma** sin distinguir mayúsculas ni espacios (`NombreNormalizado`).
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
        $enEdicion = $this->route('tipo') instanceof TipoActivo;
        $idsAutorizadas = app(AccesoEmpresa::class)->idsAutorizados($this->user())->all();

        return [
            ...($enEdicion ? [] : [
                'empresa_ids' => ['required', 'array', 'min:1'],
                'empresa_ids.*' => ['integer', Rule::in($idsAutorizadas)],
            ]),
            'nombre' => ['required', 'string', 'max:120'],
            'activo' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $nombre = $this->input('nombre');

            if (! is_string($nombre) || trim($nombre) === '') {
                return;
            }

            $tipo = $this->route('tipo');
            $ignorar = $tipo instanceof TipoActivo ? $tipo->getKey() : null;

            if (TipoActivo::existeNombre($nombre, $ignorar)) {
                $validator->errors()->add('nombre', 'Ya existe un tipo de activo con ese nombre.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'empresa_ids.required' => 'Elige al menos una empresa para la que habilitar el tipo.',
            'empresa_ids.*.in' => 'Una de las empresas seleccionadas está fuera de tu alcance.',
            'nombre.required' => 'El nombre del tipo de activo es obligatorio.',
        ];
    }
}
