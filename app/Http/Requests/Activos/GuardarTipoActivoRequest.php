<?php

namespace App\Http\Requests\Activos;

use App\Http\Requests\Concerns\NormalizaEntrada;
use App\Http\Requests\Concerns\ResuelveEmpresa;
use App\Models\TipoActivo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Alta / edición de un tipo de activo. En alta la empresa llega en `empresa_id`
 * y se valida el acceso del usuario; en edición queda fijada por el registro. El
 * nombre es único por empresa sin distinguir mayúsculas ni espacios sobrantes
 * (ver `NombreNormalizado`).
 */
class GuardarTipoActivoRequest extends FormRequest
{
    use NormalizaEntrada, ResuelveEmpresa;

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
        $tipo = $this->route('tipo');
        $tipoId = $tipo instanceof TipoActivo ? $tipo->getKey() : null;

        return [
            ...($tipoId === null ? ['empresa_id' => ['required', 'integer']] : []),
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
            $empresaId = $this->empresaResuelta('tipo')->getKey();

            if (TipoActivo::existeNombreEnEmpresa($empresaId, $nombre, $ignorar)) {
                $validator->errors()->add('nombre', 'Ya existe un tipo de activo con ese nombre en esta empresa.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'empresa_id.required' => 'Selecciona la empresa del tipo de activo.',
            'nombre.required' => 'El nombre del tipo de activo es obligatorio.',
        ];
    }
}
