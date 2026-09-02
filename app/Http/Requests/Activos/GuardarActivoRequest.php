<?php

namespace App\Http\Requests\Activos;

use App\Enums\TipoControlActivo;
use App\Http\Requests\Concerns\NormalizaEntrada;
use App\Models\Activo;
use App\Soporte\ContextoEmpresa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Validación de alta y edición de activos. La empresa nunca llega del frontend:
 * se toma de la empresa activa del contexto y es la autoridad final. Las tallas
 * son opcionales (un uniforme las usa; una laptop no).
 */
class GuardarActivoRequest extends FormRequest
{
    use NormalizaEntrada;

    public function authorize(): bool
    {
        $activo = $this->route('activo');

        return $activo instanceof Activo
            ? ($this->user()?->can('update', $activo) ?? false)
            : ($this->user()?->can('create', Activo::class) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $codigo = $this->limpiar($this->input('codigo'));

        $this->merge([
            'nombre' => $this->limpiar($this->input('nombre')),
            'codigo' => $codigo === null ? null : Str::upper($codigo),
            'descripcion' => $this->limpiar($this->input('descripcion')),
            'categoria' => $this->limpiar($this->input('categoria')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $empresaId = app(ContextoEmpresa::class)->empresaObligatoria()->getKey();
        $activo = $this->route('activo');
        $activoId = $activo instanceof Activo ? $activo->getKey() : null;

        return [
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'categoria' => ['nullable', 'string', 'max:255'],
            'codigo' => [
                'nullable', 'string', 'max:60', 'alpha_dash',
                Rule::unique('activos', 'codigo')
                    ->where(fn ($q) => $q->where('empresa_id', $empresaId))
                    ->ignore($activoId),
            ],
            'tipo_activo_id' => [
                'nullable', 'integer',
                Rule::exists('tipos_activo', 'id')->where(fn ($q) => $q->where('empresa_id', $empresaId)),
            ],
            'tipo_control' => ['required', new Enum(TipoControlActivo::class)],
            'activo' => ['boolean'],
            'tallas' => ['nullable', 'array'],
            'tallas.*' => [
                'integer',
                Rule::exists('tallas', 'id')->where(fn ($q) => $q->where('empresa_id', $empresaId)),
            ],
            'imagen' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre del activo es obligatorio.',
            'nombre.max' => 'El nombre no puede superar los 255 caracteres.',
            'codigo.alpha_dash' => 'El código sólo admite letras, números, guiones y guiones bajos.',
            'codigo.unique' => 'Ese código de activo ya existe en esta empresa.',
            'tipo_activo_id.exists' => 'El tipo de activo seleccionado no pertenece a esta empresa.',
            'tipo_control.enum' => 'El tipo de control debe ser "por cantidad" o "serializado".',
            'tipo_control.required' => 'Indica cómo se controla el activo.',
            'tallas.*.exists' => 'Una de las variantes / tallas seleccionadas no pertenece a esta empresa.',
            'imagen.image' => 'El archivo debe ser una imagen.',
            'imagen.mimes' => 'La imagen debe ser JPG, PNG o WebP.',
            'imagen.max' => 'La imagen no puede superar los 4 MB.',
        ];
    }
}
