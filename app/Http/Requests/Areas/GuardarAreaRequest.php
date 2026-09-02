<?php

namespace App\Http\Requests\Areas;

use App\Http\Requests\Concerns\NormalizaEntrada;
use App\Models\Area;
use App\Soporte\ContextoEmpresa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Validación de alta y edición de áreas/departamentos. La empresa nunca llega
 * del frontend: se toma de la empresa activa del contexto y es la autoridad
 * final.
 */
class GuardarAreaRequest extends FormRequest
{
    use NormalizaEntrada;

    public function authorize(): bool
    {
        $area = $this->route('area');

        return $area instanceof Area
            ? ($this->user()?->can('update', $area) ?? false)
            : ($this->user()?->can('create', Area::class) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $codigo = $this->limpiar($this->input('codigo'));
        $nombre = $this->limpiar($this->input('nombre'));

        $this->merge([
            'nombre' => $nombre === null ? null : (string) preg_replace('/\s+/u', ' ', $nombre),
            'codigo' => $codigo === null ? null : Str::upper($codigo),
            'descripcion' => $this->limpiar($this->input('descripcion')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $empresaId = app(ContextoEmpresa::class)->empresaObligatoria()->getKey();
        $area = $this->route('area');
        $areaId = $area instanceof Area ? $area->getKey() : null;

        return [
            'nombre' => [
                'required', 'string', 'max:255',
                Rule::unique('areas', 'nombre')
                    ->where(fn ($q) => $q->where('empresa_id', $empresaId))
                    ->ignore($areaId),
            ],
            'codigo' => [
                'nullable', 'string', 'max:60', 'alpha_dash',
                Rule::unique('areas', 'codigo')
                    ->where(fn ($q) => $q->where('empresa_id', $empresaId))
                    ->ignore($areaId),
            ],
            'descripcion' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre del área es obligatorio.',
            'nombre.max' => 'El nombre no puede superar los 255 caracteres.',
            'nombre.unique' => 'Ya existe un área con ese nombre en esta empresa.',
            'codigo.alpha_dash' => 'El código sólo admite letras, números, guiones y guiones bajos.',
            'codigo.unique' => 'Ese código de área ya existe en esta empresa.',
            'codigo.max' => 'El código no puede superar los 60 caracteres.',
            'descripcion.max' => 'La descripción no puede superar los 1000 caracteres.',
        ];
    }
}
