<?php

namespace App\Http\Requests\Empresas;

use App\Models\Empresa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Validación de alta y edición de empresas. Es la autoridad final: el frontend
 * puede adelantar mensajes, pero aquí se revalida todo.
 */
class GuardarEmpresaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $empresa = $this->route('empresa');

        return $empresa instanceof Empresa
            ? ($this->user()?->can('update', $empresa) ?? false)
            : ($this->user()?->can('create', Empresa::class) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $telefono = $this->limpiar($this->input('telefono'));
        $telefonoDigitos = $telefono === null ? null : preg_replace('/\D+/', '', $telefono);

        $this->merge([
            'nombre_comercial' => $this->limpiar($this->input('nombre_comercial')),
            'razon_social' => $this->limpiar($this->input('razon_social')),
            'rfc' => $this->rfc(),
            'codigo' => $this->codigo(),
            'telefono' => ($telefonoDigitos === '' || $telefonoDigitos === null) ? null : $telefonoDigitos,
            'correo' => $this->limpiar($this->input('correo')),
            'direccion' => $this->limpiar($this->input('direccion')),
            'activa' => $this->boolean('activa'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $empresa = $this->route('empresa');
        $empresaId = $empresa instanceof Empresa ? $empresa->getKey() : null;

        return [
            'nombre_comercial' => ['required', 'string', 'max:255'],
            'razon_social' => ['nullable', 'string', 'max:255'],
            'rfc' => ['nullable', 'string', 'max:13', 'regex:/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{0,3}$/'],
            'codigo' => [
                'nullable', 'string', 'max:20', 'alpha_dash',
                Rule::unique('empresas', 'codigo')->ignore($empresaId),
            ],
            'telefono' => ['nullable', 'string', 'digits:10'],
            'correo' => ['nullable', 'email', 'max:255'],
            'direccion' => ['nullable', 'string', 'max:500'],
            'activa' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nombre_comercial.required' => 'El nombre comercial es obligatorio.',
            'nombre_comercial.max' => 'El nombre comercial no puede superar los 255 caracteres.',
            'razon_social.max' => 'La razón social no puede superar los 255 caracteres.',
            'rfc.regex' => 'El RFC no tiene un formato válido (por ejemplo: ABC010203XYZ).',
            'rfc.max' => 'El RFC no puede superar los 13 caracteres.',
            'codigo.alpha_dash' => 'El código sólo admite letras, números, guiones y guiones bajos.',
            'codigo.unique' => 'Ese código ya está en uso por otra empresa.',
            'codigo.max' => 'El código no puede superar los 20 caracteres.',
            'telefono.digits' => 'El teléfono debe contener 10 dígitos.',
            'correo.email' => 'Introduce un correo electrónico válido.',
            'direccion.max' => 'La dirección no puede superar los 500 caracteres.',
        ];
    }

    private function limpiar(mixed $valor): ?string
    {
        if (! is_string($valor) && ! is_numeric($valor)) {
            return null;
        }

        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }

    private function rfc(): ?string
    {
        $rfc = $this->limpiar($this->input('rfc'));

        return $rfc === null ? null : Str::upper($rfc);
    }

    private function codigo(): ?string
    {
        $codigo = $this->limpiar($this->input('codigo'));

        return $codigo === null ? null : Str::upper($codigo);
    }
}
