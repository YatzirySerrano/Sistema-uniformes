<?php

namespace App\Http\Requests\Colaboradores;

use App\Http\Requests\Concerns\NormalizaEntrada;
use App\Http\Requests\Concerns\ResuelveEmpresa;
use App\Models\Colaborador;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Alta / edición de colaboradores. En alta la empresa llega en `empresa_id` y se
 * valida el acceso del usuario; en edición queda fijada por el registro.
 */
class GuardarColaboradorRequest extends FormRequest
{
    use NormalizaEntrada, ResuelveEmpresa;

    /**
     * Estructura de una CURP mexicana (18 caracteres), sin verificar contra
     * RENAPO ni recalcular el dígito verificador: 4 letras (2.º carácter
     * vocal) + fecha de nacimiento AAMMDD + sexo H/M + entidad (2 letras, de
     * un catálogo fijo de 32 claves) + 3 consonantes + 1 alfanumérico
     * (diferenciador) + 1 dígito (verificador). Rechaza longitudes o
     * caracteres claramente inválidos sin pretender validar la identidad real.
     *
     * Pública para que `ColaboradorController::validarCurp()` (comprobación
     * anticipada de disponibilidad) reutilice exactamente el mismo formato,
     * sin duplicar el patrón.
     */
    public const REGEX_CURP = '/^[A-Z][AEIOU][A-Z]{2}\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])[HM]'
        .'(AS|BC|BS|CC|CL|CM|CS|CH|DF|DG|GT|GR|HG|JC|MC|MN|MS|NT|NL|OC|PL|QO|QR|SL|SP|SR|TC|TL|TS|VZ|YN|ZS|NE)'
        .'[B-DF-HJ-NP-TV-Z]{3}[A-Z0-9]\d$/';

    protected function prepareForValidation(): void
    {
        $this->merge([
            'curp' => $this->curp(),
        ]);
    }

    private function curp(): ?string
    {
        $curp = $this->limpiar($this->input('curp'));

        return $curp === null ? null : Str::upper($curp);
    }

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
            'curp' => [
                'required', 'string', 'size:18',
                'regex:'.self::REGEX_CURP,
                Rule::unique('colaboradores', 'curp')->ignore($colaboradorId),
            ],
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
            'curp.required' => 'La CURP es obligatoria.',
            'curp.size' => 'La CURP debe tener exactamente 18 caracteres.',
            'curp.regex' => 'La CURP no tiene un formato válido.',
            'curp.unique' => 'Ya existe un colaborador registrado con esta CURP.',
            'sucursal_id.exists' => 'La sucursal seleccionada no pertenece a esta empresa.',
            'area_id.exists' => 'El área seleccionada no pertenece a esta empresa.',
        ];
    }
}
