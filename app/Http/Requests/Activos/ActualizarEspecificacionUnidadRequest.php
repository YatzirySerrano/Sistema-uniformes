<?php

namespace App\Http\Requests\Activos;

use App\Models\UnidadActivo;
use App\Soporte\EspecificacionUnidad;
use App\Soporte\ResolverPerfilTecnicoUnidad;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Edita los datos técnicos (ver `App\Soporte\EspecificacionUnidad`)
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

        return EspecificacionUnidad::reglas('', [
            Rule::unique('unidad_activo_especificaciones', 'imei')->ignore($unidad->getKey(), 'unidad_activo_id'),
        ]);
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

            EspecificacionUnidad::validarPorPerfil($validator, $perfil, $this->all());
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...EspecificacionUnidad::mensajes(),
            'imei.unique' => 'Ya existe una unidad registrada con ese IMEI.',
        ];
    }
}
