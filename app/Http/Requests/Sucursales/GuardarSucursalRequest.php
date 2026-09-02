<?php

namespace App\Http\Requests\Sucursales;

use App\Http\Requests\Concerns\NormalizaEntrada;
use App\Models\Sucursal;
use App\Soporte\ContextoEmpresa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Validación de alta y edición de sucursales. La empresa nunca llega del
 * frontend: se toma de la empresa activa del contexto y es la autoridad final.
 */
class GuardarSucursalRequest extends FormRequest
{
    use NormalizaEntrada;

    public function authorize(): bool
    {
        $sucursal = $this->route('sucursal');

        return $sucursal instanceof Sucursal
            ? ($this->user()?->can('update', $sucursal) ?? false)
            : ($this->user()?->can('create', Sucursal::class) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $codigo = $this->limpiar($this->input('codigo'));

        $this->merge([
            'nombre' => $this->limpiar($this->input('nombre')),
            'codigo' => $codigo === null ? null : Str::upper($codigo),
            'direccion' => $this->limpiar($this->input('direccion')),
            'telefono' => $this->soloDigitos($this->input('telefono')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $empresaId = app(ContextoEmpresa::class)->empresaObligatoria()->getKey();
        $sucursal = $this->route('sucursal');
        $sucursalId = $sucursal instanceof Sucursal ? $sucursal->getKey() : null;

        return [
            'nombre' => ['required', 'string', 'max:255'],
            'codigo' => [
                'nullable', 'string', 'max:60', 'alpha_dash',
                Rule::unique('sucursales', 'codigo')
                    ->where(fn ($q) => $q->where('empresa_id', $empresaId))
                    ->ignore($sucursalId),
            ],
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
            'nombre.required' => 'El nombre de la sucursal es obligatorio.',
            'nombre.max' => 'El nombre no puede superar los 255 caracteres.',
            'codigo.alpha_dash' => 'El código sólo admite letras, números, guiones y guiones bajos.',
            'codigo.unique' => 'Ese código de sucursal ya existe en esta empresa.',
            'codigo.max' => 'El código no puede superar los 60 caracteres.',
            'direccion.max' => 'La dirección no puede superar los 255 caracteres.',
            'telefono.digits' => 'El teléfono debe contener 10 dígitos.',
        ];
    }
}
