<?php

namespace App\Http\Requests\Configuracion;

use App\Models\ConfiguracionSistema;
use App\Soporte\ColorContraste;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class GuardarConfiguracionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('actualizar', ConfiguracionSistema::class);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $hex = ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'];

        return [
            'color_principal' => $hex,
            'color_hover_principal' => $hex,
            'color_texto_boton_principal' => $hex,
            'fondo_general' => $hex,
            'fondo_tarjetas' => $hex,
            'fondo_sidebar' => $hex,
            'color_secundario' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'regex' => 'Debe ser un color hexadecimal válido (#RRGGBB).',
            'required' => 'Este color es obligatorio.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $datos = $validator->getData();

            $principal = $datos['color_principal'] ?? null;
            $texto = $datos['color_texto_boton_principal'] ?? null;

            if (is_string($principal) && is_string($texto)
                && preg_match('/^#[0-9a-fA-F]{6}$/', $principal)
                && preg_match('/^#[0-9a-fA-F]{6}$/', $texto)
                && ColorContraste::razonContraste($principal, $texto) < 3.0
            ) {
                $validator->errors()->add(
                    'color_texto_boton_principal',
                    'El texto sobre el botón principal no tiene suficiente contraste con el color principal. Elige un color más claro u oscuro para que siga siendo legible.'
                );
            }
        });
    }
}
