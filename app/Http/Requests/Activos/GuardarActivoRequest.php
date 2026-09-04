<?php

namespace App\Http\Requests\Activos;

use App\Enums\TipoControlActivo;
use App\Http\Requests\Concerns\NormalizaEntrada;
use App\Http\Requests\Concerns\ResuelveEmpresa;
use App\Models\Activo;
use App\Models\CategoriaActivo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

/**
 * Validación de alta y edición de activos. En alta la empresa llega en
 * `empresa_id` y se valida el acceso del usuario; en edición queda fijada por el
 * registro. Las tallas son opcionales (un uniforme las usa; una laptop no).
 */
class GuardarActivoRequest extends FormRequest
{
    use NormalizaEntrada, ResuelveEmpresa;

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
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $empresaId = $this->empresaResuelta('activo')->getKey();
        $activo = $this->route('activo');
        $activoId = $activo instanceof Activo ? $activo->getKey() : null;

        return [
            ...($activoId === null ? ['empresa_id' => ['required', 'integer']] : []),
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'categoria_id' => [
                'nullable', 'integer',
                Rule::exists('categoria_activo_empresa', 'categoria_activo_id')->where(fn ($q) => $q->where('empresa_id', $empresaId)),
            ],
            'codigo' => [
                'nullable', 'string', 'max:60', 'alpha_dash',
                Rule::unique('activos', 'codigo')
                    ->where(fn ($q) => $q->where('empresa_id', $empresaId))
                    ->ignore($activoId),
            ],
            'tipo_activo_id' => [
                'nullable', 'integer',
                Rule::exists('tipo_activo_empresa', 'tipo_activo_id')->where(fn ($q) => $q->where('empresa_id', $empresaId)),
            ],
            'tipo_control' => ['required', new Enum(TipoControlActivo::class)],
            'activo' => ['boolean'],
            'tallas' => ['nullable', 'array'],
            'tallas.*' => [
                'integer',
                Rule::exists('talla_empresa', 'talla_id')->where(fn ($q) => $q->where('empresa_id', $empresaId)),
            ],
            'imagen' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }

    /**
     * Coherencia tipo ↔ categoría: si el activo lleva tipo y categoría, y la
     * categoría está ligada a un tipo concreto, ambos deben coincidir. Una
     * categoría sin tipo, o un activo sin tipo, no tienen restricción (tipo y
     * categoría son opcionales).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $tipoId = $this->integer('tipo_activo_id') ?: null;
            $categoriaId = $this->integer('categoria_id') ?: null;

            if ($tipoId === null || $categoriaId === null) {
                return;
            }

            // La categoría y el tipo ya están acotados a la empresa por las
            // reglas `exists` sobre los pivotes; aquí sólo se valida coherencia.
            $tipoDeLaCategoria = CategoriaActivo::query()
                ->whereKey($categoriaId)
                ->value('tipo_activo_id');

            if ($tipoDeLaCategoria !== null && (int) $tipoDeLaCategoria !== $tipoId) {
                $validator->errors()->add(
                    'categoria_id',
                    'La categoría seleccionada pertenece a otro tipo de activo. Cámbiala o quita el tipo.',
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'empresa_id.required' => 'Selecciona la empresa del activo.',
            'nombre.required' => 'El nombre del activo es obligatorio.',
            'nombre.max' => 'El nombre no puede superar los 255 caracteres.',
            'codigo.alpha_dash' => 'El código sólo admite letras, números, guiones y guiones bajos.',
            'codigo.unique' => 'Ese código de activo ya existe en esta empresa.',
            'tipo_activo_id.exists' => 'El tipo de activo seleccionado no pertenece a esta empresa.',
            'categoria_id.exists' => 'La categoría seleccionada no pertenece a esta empresa.',
            'tipo_control.enum' => 'El tipo de control debe ser "por cantidad" o "serializado".',
            'tipo_control.required' => 'Indica cómo se controla el activo.',
            'tallas.*.exists' => 'Una de las variantes / tallas seleccionadas no pertenece a esta empresa.',
            'imagen.image' => 'El archivo debe ser una imagen.',
            'imagen.mimes' => 'La imagen debe ser JPG, PNG o WebP.',
            'imagen.max' => 'La imagen no puede superar los 4 MB.',
        ];
    }
}
