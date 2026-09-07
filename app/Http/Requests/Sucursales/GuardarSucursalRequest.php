<?php

namespace App\Http\Requests\Sucursales;

use App\Http\Requests\Concerns\NormalizaEntrada;
use App\Http\Requests\Concerns\ResuelveEmpresa;
use App\Models\Sucursal;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de alta y edición de sucursales. En alta la empresa llega en
 * `empresa_id` y se valida el acceso del usuario (ResuelveEmpresa); en edición
 * queda fijada por el registro.
 */
class GuardarSucursalRequest extends FormRequest
{
    use NormalizaEntrada, ResuelveEmpresa;

    public function authorize(): bool
    {
        $sucursal = $this->route('sucursal');

        return $sucursal instanceof Sucursal
            ? ($this->user()?->can('update', $sucursal) ?? false)
            : ($this->user()?->can('create', Sucursal::class) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nombre' => $this->limpiar($this->input('nombre')),
            'direccion' => $this->limpiar($this->input('direccion')),
            'telefono' => $this->soloDigitos($this->input('telefono')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Valida el acceso a la empresa (alta) o al registro (edición) aquí
        // mismo, aunque el id ya no se use para la unicidad de `codigo`.
        $this->empresaResuelta('sucursal');
        $sucursal = $this->route('sucursal');
        $sucursalId = $sucursal instanceof Sucursal ? $sucursal->getKey() : null;

        return [
            ...($sucursalId === null ? ['empresa_id' => ['required', 'integer']] : []),
            'nombre' => ['required', 'string', 'max:255'],
            // `codigo` NUNCA se valida como entrada del usuario: lo genera
            // el backend (autogenerado, SUC-0001…) en el alta y es
            // inmutable en edición.
            'direccion' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'digits:10'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'empresa_id.required' => 'Selecciona la empresa de la sucursal.',
            'nombre.required' => 'El nombre de la sucursal es obligatorio.',
            'nombre.max' => 'El nombre no puede superar los 255 caracteres.',
            'direccion.max' => 'La dirección no puede superar los 255 caracteres.',
            'telefono.digits' => 'El teléfono debe contener 10 dígitos.',
        ];
    }
}
