<?php

namespace App\Http\Requests\Activos;

use App\Http\Requests\Concerns\NormalizaEntrada;
use App\Models\TipoActivo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Alta / edición de un tipo de activo del catálogo GLOBAL. Queda visible de
 * inmediato para todas las empresas; no hay habilitación por empresa. El
 * nombre es único **a nivel plataforma** sin distinguir mayúsculas ni espacios
 * (`NombreNormalizado`).
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
        return [
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
            'nombre.required' => 'El nombre del tipo de activo es obligatorio.',
        ];
    }
}
