<?php

namespace App\Http\Requests\Colaboradores;

use App\Models\Colaborador;
use App\Soporte\ContextoEmpresa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarColaboradorRequest extends FormRequest
{
    public function authorize(): bool
    {
        $colaborador = $this->route('colaborador');

        return $colaborador instanceof Colaborador
            ? $this->user()?->can('update', $colaborador) ?? false
            : $this->user()?->can('create', Colaborador::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $empresaId = app(ContextoEmpresa::class)->empresaObligatoria()->getKey();
        $colaborador = $this->route('colaborador');
        $colaboradorId = $colaborador instanceof Colaborador ? $colaborador->getKey() : null;

        return [
            'numero_empleado' => [
                'required', 'string', 'max:60',
                Rule::unique('colaboradores', 'numero_empleado')
                    ->where(fn ($q) => $q->where('empresa_id', $empresaId))
                    ->ignore($colaboradorId),
            ],
            'nombre_completo' => ['required', 'string', 'max:255'],
            'sucursal_id' => [
                'required', 'integer',
                Rule::exists('sucursales', 'id')->where(fn ($q) => $q->where('empresa_id', $empresaId)),
            ],
            'puesto' => ['nullable', 'string', 'max:255'],
            'area' => ['nullable', 'string', 'max:255'],
            'correo' => ['nullable', 'email', 'max:255'],
            'activo' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'numero_empleado.unique' => 'El número de empleado ya se encuentra registrado en esta empresa.',
            'sucursal_id.exists' => 'La sucursal seleccionada no pertenece a esta empresa.',
        ];
    }
}
