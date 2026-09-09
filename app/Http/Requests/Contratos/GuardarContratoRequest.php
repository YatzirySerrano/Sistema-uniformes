<?php

namespace App\Http\Requests\Contratos;

use App\Http\Requests\Concerns\NormalizaEntrada;
use App\Http\Requests\Concerns\ResuelveEmpresa;
use App\Models\Contrato;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validación de alta y edición de contratos. En alta la empresa llega en
 * `empresa_id` y se valida el acceso del usuario (ResuelveEmpresa); en
 * edición la empresa queda fijada por el registro. Nunca se confía en el
 * `empresa_id` del frontend sin validar.
 */
class GuardarContratoRequest extends FormRequest
{
    use NormalizaEntrada, ResuelveEmpresa;

    public function authorize(): bool
    {
        $contrato = $this->route('contrato');

        return $contrato instanceof Contrato
            ? ($this->user()?->can('update', $contrato) ?? false)
            : ($this->user()?->can('create', Contrato::class) ?? false);
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
        $empresaId = $this->empresaResuelta('contrato')->getKey();
        $contrato = $this->route('contrato');
        $contratoId = $contrato instanceof Contrato ? $contrato->getKey() : null;

        return [
            // Sólo en alta: en edición la empresa queda fijada por el registro.
            ...($contratoId === null ? ['empresa_id' => ['required', 'integer']] : []),
            'nombre' => [
                'required', 'string', 'max:255',
                Rule::unique('contratos', 'nombre')
                    ->where(fn ($q) => $q->where('empresa_id', $empresaId))
                    ->ignore($contratoId),
            ],
            // `codigo` NUNCA se valida como entrada del usuario: lo genera
            // el backend (autogenerado, CON-0001…) en el alta y es
            // inmutable en edición.
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'fecha_inicio' => ['nullable', 'date'],
            'fecha_fin' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('fecha_inicio') && $this->filled('fecha_fin')
                && $this->input('fecha_fin') < $this->input('fecha_inicio')) {
                $validator->errors()->add('fecha_fin', 'La fecha de fin no puede ser anterior a la fecha de inicio.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'empresa_id.required' => 'Selecciona la empresa del contrato.',
            'nombre.required' => 'El nombre del contrato es obligatorio.',
            'nombre.max' => 'El nombre no puede superar los 255 caracteres.',
            'nombre.unique' => 'Ya existe un contrato con ese nombre en esta empresa.',
            'descripcion.max' => 'La descripción no puede superar los 1000 caracteres.',
            'fecha_fin.after_or_equal' => 'La fecha de fin no puede ser anterior a la fecha de inicio.',
        ];
    }
}
