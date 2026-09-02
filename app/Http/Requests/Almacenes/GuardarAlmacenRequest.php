<?php

namespace App\Http\Requests\Almacenes;

use App\Http\Requests\Concerns\NormalizaEntrada;
use App\Models\Almacen;
use App\Soporte\ContextoEmpresa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Validación de alta y edición de almacenes. La empresa nunca llega del
 * frontend: se toma de la empresa activa del contexto y es la autoridad final.
 * Todas las relaciones (responsable, sucursales abastecidas) se validan contra
 * la empresa activa.
 */
class GuardarAlmacenRequest extends FormRequest
{
    use NormalizaEntrada;

    public function authorize(): bool
    {
        $almacen = $this->route('almacen');

        return $almacen instanceof Almacen
            ? ($this->user()?->can('update', $almacen) ?? false)
            : ($this->user()?->can('create', Almacen::class) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $codigo = $this->limpiar($this->input('codigo'));

        $this->merge([
            'nombre' => $this->limpiar($this->input('nombre')),
            'codigo' => $codigo === null ? null : Str::upper($codigo),
            'descripcion' => $this->limpiar($this->input('descripcion')),
            'direccion' => $this->limpiar($this->input('direccion')),
            'telefono' => $this->soloDigitos($this->input('telefono')),
            'correo' => $this->limpiar($this->input('correo')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $empresaId = app(ContextoEmpresa::class)->empresaObligatoria()->getKey();
        $almacen = $this->route('almacen');
        $almacenId = $almacen instanceof Almacen ? $almacen->getKey() : null;

        return [
            'nombre' => ['required', 'string', 'max:255'],
            'codigo' => [
                'nullable', 'string', 'max:60', 'alpha_dash',
                Rule::unique('almacenes', 'codigo')
                    ->where(fn ($q) => $q->where('empresa_id', $empresaId))
                    ->ignore($almacenId),
            ],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'digits:10'],
            'correo' => ['nullable', 'email', 'max:255'],
            'responsable_colaborador_id' => [
                'nullable', 'integer',
                Rule::exists('colaboradores', 'id')->where(fn ($q) => $q
                    ->where('empresa_id', $empresaId)
                    ->where('activo', true)),
            ],
            'sucursales' => ['nullable', 'array'],
            'sucursales.*' => [
                'integer',
                Rule::exists('sucursales', 'id')->where(fn ($q) => $q->where('empresa_id', $empresaId)),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre del almacén es obligatorio.',
            'nombre.max' => 'El nombre no puede superar los 255 caracteres.',
            'codigo.alpha_dash' => 'El código sólo admite letras, números, guiones y guiones bajos.',
            'codigo.unique' => 'Ese código de almacén ya existe en esta empresa.',
            'codigo.max' => 'El código no puede superar los 60 caracteres.',
            'direccion.max' => 'La dirección no puede superar los 255 caracteres.',
            'telefono.digits' => 'El teléfono debe contener 10 dígitos.',
            'correo.email' => 'El correo no tiene un formato válido.',
            'responsable_colaborador_id.exists' => 'El colaborador seleccionado como responsable no es válido para esta empresa.',
            'sucursales.*.exists' => 'Una de las sucursales seleccionadas no pertenece a esta empresa.',
        ];
    }
}
