<?php

namespace App\Http\Requests\Areas;

use App\Http\Requests\Concerns\NormalizaEntrada;
use App\Http\Requests\Concerns\ResuelveEmpresa;
use App\Models\Area;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación de alta y edición de áreas/departamentos. En alta la empresa llega
 * en `empresa_id` y se valida el acceso del usuario (ResuelveEmpresa); en
 * edición la empresa queda fijada por el registro. Nunca se confía en el
 * `empresa_id` del frontend sin validar.
 */
class GuardarAreaRequest extends FormRequest
{
    use NormalizaEntrada, ResuelveEmpresa;

    public function authorize(): bool
    {
        $area = $this->route('area');

        return $area instanceof Area
            ? ($this->user()?->can('update', $area) ?? false)
            : ($this->user()?->can('create', Area::class) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $nombre = $this->limpiar($this->input('nombre'));

        $this->merge([
            'nombre' => $nombre === null ? null : (string) preg_replace('/\s+/u', ' ', $nombre),
            'descripcion' => $this->limpiar($this->input('descripcion')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $empresaId = $this->empresaResuelta('area')->getKey();
        $area = $this->route('area');
        $areaId = $area instanceof Area ? $area->getKey() : null;

        return [
            // Sólo en alta: en edición la empresa queda fijada por el registro.
            ...($areaId === null ? ['empresa_id' => ['required', 'integer']] : []),
            'nombre' => [
                'required', 'string', 'max:255',
                Rule::unique('areas', 'nombre')
                    ->where(fn ($q) => $q->where('empresa_id', $empresaId))
                    ->ignore($areaId),
            ],
            // `codigo` NUNCA se valida como entrada del usuario: lo genera
            // el backend (autogenerado, ARE-0001…) en el alta y es
            // inmutable en edición.
            'descripcion' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'empresa_id.required' => 'Selecciona la empresa del área.',
            'nombre.required' => 'El nombre del área es obligatorio.',
            'nombre.max' => 'El nombre no puede superar los 255 caracteres.',
            'nombre.unique' => 'Ya existe un área con ese nombre en esta empresa.',
            'descripcion.max' => 'La descripción no puede superar los 1000 caracteres.',
        ];
    }
}
