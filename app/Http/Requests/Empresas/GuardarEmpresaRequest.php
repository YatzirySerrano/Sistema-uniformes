<?php

namespace App\Http\Requests\Empresas;

use App\Http\Requests\Concerns\NormalizaEntrada;
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
    use NormalizaEntrada;

    // Persona moral: 3 letras + 6 dígitos + 3 alfanumérico (homoclave) = 12.
    // Persona física: 4 letras + 6 dígitos + 3 alfanumérico (homoclave) = 13.
    // La homoclave SIEMPRE son 3 caracteres (nunca 0-2). Pública para que
    // `EmpresaController::validarRfc()` (comprobación anticipada de
    // disponibilidad) reutilice exactamente el mismo formato.
    public const REGEX_RFC = '/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/';

    public function authorize(): bool
    {
        $empresa = $this->route('empresa');

        return $empresa instanceof Empresa
            ? ($this->user()?->can('update', $empresa) ?? false)
            : ($this->user()?->can('create', Empresa::class) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nombre_comercial' => $this->limpiar($this->input('nombre_comercial')),
            'razon_social' => $this->limpiar($this->input('razon_social')),
            'rfc' => $this->rfc(),
            'telefono' => $this->soloDigitos($this->input('telefono')),
            'correo' => $this->limpiar($this->input('correo')),
            'direccion' => $this->limpiar($this->input('direccion')),
            'activa' => $this->boolean('activa'),
            'eliminar_logo' => $this->boolean('eliminar_logo'),
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
            'rfc' => [
                'required', 'string', 'max:13',
                'regex:'.self::REGEX_RFC,
                Rule::unique('empresas', 'rfc')->ignore($empresaId),
            ],
            // `codigo` NUNCA se valida como entrada del usuario: lo genera
            // el backend (autogenerado a partir del nombre comercial) en el
            // alta y es inmutable en edición.
            'telefono' => ['nullable', 'string', 'digits:10'],
            'correo' => ['nullable', 'email', 'max:255'],
            'direccion' => ['nullable', 'string', 'max:500'],
            'activa' => ['boolean'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg', 'max:2048'],
            // Bandera explícita "eliminar el logotipo actual" (Caso C/E). Un
            // `logo` nulo por sí solo significa "no tocar", nunca "eliminar".
            'eliminar_logo' => ['sometimes', 'boolean'],
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
            'rfc.required' => 'El RFC es obligatorio.',
            'rfc.regex' => 'El RFC no tiene un formato válido (por ejemplo: ABC010203XYZ).',
            'rfc.max' => 'El RFC no puede superar los 13 caracteres.',
            'rfc.unique' => 'Ya existe una empresa registrada con este RFC.',
            'telefono.digits' => 'El teléfono debe contener 10 dígitos.',
            'correo.email' => 'Introduce un correo electrónico válido.',
            'direccion.max' => 'La dirección no puede superar los 500 caracteres.',
            'logo.image' => 'El archivo seleccionado no es una imagen válida.',
            'logo.mimes' => 'El logotipo debe ser un archivo PNG, JPG, JPEG o SVG.',
            'logo.max' => 'El logotipo no puede superar los 2 MB.',
            'logo.uploaded' => 'El logotipo no pudo cargarse. Verifica que el archivo no supere los 2 MB.',
        ];
    }

    private function rfc(): ?string
    {
        $rfc = $this->limpiar($this->input('rfc'));

        return $rfc === null ? null : Str::upper($rfc);
    }
}
