<?php

namespace App\Http\Requests\Almacenes;

use App\Http\Requests\Concerns\NormalizaEntrada;
use App\Models\Almacen;
use App\Soporte\AccesoEmpresa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Validación de alta y edición de almacenes multiempresa. La empresa no es un
 * contexto global: el almacén abastece al conjunto `empresa_ids`, cada uno
 * validado contra las empresas a las que el usuario tiene acceso. El
 * `responsable` debe pertenecer a alguna de esas empresas.
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
        $almacen = $this->route('almacen');
        $almacenId = $almacen instanceof Almacen ? $almacen->getKey() : null;

        $empresasAutorizadas = $this->user() === null
            ? []
            : app(AccesoEmpresa::class)->idsAutorizados($this->user())->all();

        return [
            'nombre' => ['required', 'string', 'max:255'],
            'codigo' => [
                'nullable', 'string', 'max:60', 'alpha_dash',
                Rule::unique('almacenes', 'codigo')->ignore($almacenId),
            ],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'digits:10'],
            'correo' => ['nullable', 'email', 'max:255'],
            'empresa_ids' => ['required', 'array', 'min:1'],
            'empresa_ids.*' => ['integer', Rule::in($empresasAutorizadas)],
            'responsable_colaborador_id' => [
                'nullable', 'integer',
                Rule::exists('colaboradores', 'id')->where(fn ($q) => $q
                    ->whereIn('empresa_id', (array) $this->input('empresa_ids', []))
                    ->where('activo', true)),
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
            'codigo.unique' => 'Ese código de almacén ya existe.',
            'codigo.max' => 'El código no puede superar los 60 caracteres.',
            'direccion.max' => 'La dirección no puede superar los 255 caracteres.',
            'telefono.digits' => 'El teléfono debe contener 10 dígitos.',
            'correo.email' => 'El correo no tiene un formato válido.',
            'empresa_ids.required' => 'Selecciona al menos una empresa abastecida.',
            'empresa_ids.min' => 'Selecciona al menos una empresa abastecida.',
            'empresa_ids.*.in' => 'Una de las empresas seleccionadas no está dentro de tu alcance.',
            'responsable_colaborador_id.exists' => 'El colaborador seleccionado como responsable no pertenece a las empresas abastecidas.',
        ];
    }
}
