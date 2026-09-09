<?php

namespace App\Http\Requests\Colaboradores;

use App\Http\Requests\Concerns\NormalizaEntrada;
use App\Models\Colaborador;
use App\Models\Servicio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Cambia únicamente la ubicación operativa VIGENTE del colaborador
 * (`servicio_actual_id`). `servicio_id` es nullable: permite dejarlo "sin
 * servicio" (personal administrativo, fuera de servicio temporalmente,
 * pendiente de asignación). Reutiliza el permiso `colaboradores.editar` (la
 * misma Policy `update` que el resto de la edición del colaborador) — el
 * pliego pide no crear permisos absurdamente granulares para una acción
 * puntual sobre un recurso que ya tiene su propio permiso de edición.
 */
class CambiarServicioColaboradorRequest extends FormRequest
{
    use NormalizaEntrada;

    public function authorize(): bool
    {
        $colaborador = $this->route('colaborador');

        return $colaborador instanceof Colaborador
            && ($this->user()?->can('update', $colaborador) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['motivo' => $this->limpiar($this->input('motivo'))]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'servicio_id' => ['nullable', 'integer', Rule::exists('servicios', 'id')->where('activo', true)],
            'motivo' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * El contrato del servicio debe estar activo y su empresa debe coincidir
     * con la del colaborador — nunca se confía en lo que muestre el
     * combobox del frontend.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $servicioId = $this->input('servicio_id');

            if ($servicioId === null || $validator->errors()->has('servicio_id')) {
                return;
            }

            /** @var Colaborador $colaborador */
            $colaborador = $this->route('colaborador');
            $servicio = Servicio::query()->with('contrato')->find((int) $servicioId);

            if ($servicio === null) {
                return; // ya lo marcó la regla `exists`
            }

            if (! $servicio->contrato->activo) {
                $validator->errors()->add('servicio_id', 'El contrato de ese servicio está inactivo.');

                return;
            }

            if ($servicio->contrato->empresa_id !== $colaborador->empresa_id) {
                $validator->errors()->add('servicio_id', 'Ese servicio pertenece a una empresa distinta a la del colaborador.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'servicio_id.exists' => 'El servicio seleccionado no es válido o está inactivo.',
            'motivo.max' => 'El motivo no puede superar los 500 caracteres.',
        ];
    }
}
