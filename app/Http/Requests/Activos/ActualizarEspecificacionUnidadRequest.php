<?php

namespace App\Http\Requests\Activos;

use App\Models\UnidadActivo;
use App\Soporte\ResolverPerfilTecnicoUnidad;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Edita los datos técnicos (marca / modelo / IMEI / número / operador / plan)
 * de una unidad identificada EXISTENTE. Nunca toca `codigo` / `public_token` /
 * `estado` / `condicion`. El perfil (campos obligatorios) lo decide el backend
 * por el `codigo` del catálogo, nunca el frontend.
 */
class ActualizarEspecificacionUnidadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $unidad = $this->route('unidad');

        return $unidad instanceof UnidadActivo
            && ($this->user()?->can('administrar', $unidad) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var UnidadActivo $unidad */
        $unidad = $this->route('unidad');

        return [
            'marca' => ['nullable', 'string', 'max:120'],
            'modelo' => ['nullable', 'string', 'max:160'],
            'imei' => [
                'nullable', 'string', 'regex:/^[0-9]{14,17}$/',
                Rule::unique('unidad_activo_especificaciones', 'imei')->ignore($unidad->getKey(), 'unidad_activo_id'),
            ],
            'numero_telefonico' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{6,30}$/'],
            'operador' => ['nullable', 'string', 'max:80'],
            'plan' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var UnidadActivo $unidad */
            $unidad = $this->route('unidad');
            $unidad->loadMissing('activo');

            if ($unidad->activo === null) {
                return;
            }

            $perfil = app(ResolverPerfilTecnicoUnidad::class)->paraActivo($unidad->activo);

            if ($perfil === null) {
                return;
            }

            foreach ($perfil->camposRequeridos() as $campo) {
                $valor = $this->input($campo);

                if (! is_string($valor) || trim($valor) === '') {
                    $validator->errors()->add($campo, 'Este dato es obligatorio para '.$perfil->etiqueta().'.');
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'imei.regex' => 'El IMEI debe tener entre 14 y 17 dígitos.',
            'imei.unique' => 'Ya existe una unidad registrada con ese IMEI.',
            'numero_telefonico.regex' => 'El número telefónico no tiene un formato válido.',
        ];
    }
}
