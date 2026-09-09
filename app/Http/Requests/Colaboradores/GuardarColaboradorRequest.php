<?php

namespace App\Http\Requests\Colaboradores;

use App\Http\Requests\Concerns\ResuelveEmpresa;
use App\Models\Colaborador;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta / edición de colaboradores. En alta la empresa llega en `empresa_id` y se
 * valida el acceso del usuario; en edición queda fijada por el registro.
 */
class GuardarColaboradorRequest extends FormRequest
{
    use ResuelveEmpresa;

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
        $empresaId = $this->empresaResuelta('colaborador')->getKey();
        $colaborador = $this->route('colaborador');
        $colaboradorId = $colaborador instanceof Colaborador ? $colaborador->getKey() : null;

        return [
            ...($colaboradorId === null ? ['empresa_id' => ['required', 'integer']] : []),
            // `numero_empleado` NUNCA se valida como entrada del usuario: lo
            // genera el backend (App\Soporte\GeneradorNumeroEmpleado) en el
            // alta y es inmutable en edición. Cualquier valor que mande el
            // cliente para este campo se ignora (no está en las reglas, así
            // que `validated()`/`safe()` nunca lo incluyen).
            'nombre_completo' => ['required', 'string', 'max:255'],
            'sucursal_id' => [
                'required', 'integer',
                Rule::exists('sucursales', 'id')->where(fn ($q) => $q->where('empresa_id', $empresaId)),
            ],
            'puesto' => ['nullable', 'string', 'max:255'],
            'area' => ['nullable', 'string', 'max:255'],
            'area_id' => [
                'nullable', 'integer',
                Rule::exists('areas', 'id')->where(fn ($q) => $q->where('empresa_id', $empresaId)),
            ],
            'correo' => ['nullable', 'email', 'max:255'],
            'foto' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            // Bandera explícita "eliminar la foto actual" (Caso C/E). Una `foto`
            // nula por sí sola significa "no tocar", nunca "eliminar".
            'eliminar_foto' => ['sometimes', 'boolean'],
            'activo' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'empresa_id.required' => 'Selecciona la empresa del colaborador.',
            'sucursal_id.exists' => 'La sucursal seleccionada no pertenece a esta empresa.',
            'area_id.exists' => 'El área seleccionada no pertenece a esta empresa.',
        ];
    }
}
