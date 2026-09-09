<?php

namespace App\Http\Requests\Servicios;

use App\Http\Requests\Concerns\NormalizaEntrada;
use App\Models\Colaborador;
use App\Models\Servicio;
use App\Soporte\AccesoEmpresa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Asignación en lote de colaboradores a un servicio operativo, desde el
 * detalle del propio Servicio. Muta EXACTAMENTE la misma fuente de verdad que
 * "Cambiar servicio" del colaborador (`colaboradores.servicio_actual_id`) y
 * reutiliza su misma lógica central: `App\Acciones\AsignarColaboradoresServicio`
 * delega colaborador por colaborador en `CambiarServicioColaborador`.
 *
 * Nunca se confía en los ids que llegan del navegador: cada colaborador se
 * revalida contra la empresa del servicio (derivada de su contrato) y contra
 * el alcance (empresa + sucursal) del usuario. Manipular `colaborador_ids[]`
 * desde DevTools jamás debe permitir un cambio cross-empresa ni fuera de
 * alcance.
 */
class AsignarColaboradoresServicioRequest extends FormRequest
{
    use NormalizaEntrada;

    public function authorize(): bool
    {
        $servicio = $this->route('servicio');
        $usuario = $this->user();

        return $servicio instanceof Servicio
            && $usuario !== null
            && $usuario->can('view', $servicio)
            && $usuario->can('colaboradores.editar');
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
            'colaborador_ids' => ['required', 'array', 'min:1', 'max:500'],
            'colaborador_ids.*' => ['integer', 'distinct', 'exists:colaboradores,id'],
            'motivo' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Blindaje multiempresa: el servicio de destino debe estar operativo (él y
     * su contrato activos) y CADA colaborador debe pertenecer a la empresa del
     * servicio y a una sucursal dentro del alcance del usuario.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var Servicio $servicio */
            $servicio = $this->route('servicio');
            $servicio->loadMissing('contrato');

            if (! $servicio->activo || ! $servicio->contrato->activo) {
                $validator->errors()->add('colaborador_ids', 'Este servicio está inactivo (o su contrato). Actívalo antes de asignarle colaboradores.');

                return;
            }

            $empresaId = $servicio->contrato->empresa_id;
            $usuario = $this->user();

            if ($usuario === null || ! $usuario->puedeAccederEmpresa($empresaId)) {
                $validator->errors()->add('colaborador_ids', 'No tienes acceso a la empresa de este servicio.');

                return;
            }

            $sucursalesAutorizadas = app(AccesoEmpresa::class)
                ->sucursalesAutorizadas($usuario, $empresaId)
                ->pluck('id');

            /** @var array<int, int> $ids */
            $ids = array_map('intval', (array) $this->input('colaborador_ids', []));
            $colaboradores = Colaborador::query()->whereIn('id', $ids)->get()->keyBy('id');

            foreach ($ids as $indice => $id) {
                $colaborador = $colaboradores->get($id);

                if ($colaborador === null) {
                    continue; // ya lo marcó la regla `exists`
                }

                if ($colaborador->empresa_id !== $empresaId) {
                    $validator->errors()->add("colaborador_ids.{$indice}", 'Este colaborador pertenece a una empresa distinta a la del servicio.');

                    continue;
                }

                if (! $colaborador->activo) {
                    $validator->errors()->add("colaborador_ids.{$indice}", 'Este colaborador está inactivo.');

                    continue;
                }

                if (! $sucursalesAutorizadas->contains($colaborador->sucursal_id)) {
                    $validator->errors()->add("colaborador_ids.{$indice}", 'Este colaborador está en una sucursal fuera de tu alcance.');
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'colaborador_ids.required' => 'Selecciona al menos un colaborador.',
            'colaborador_ids.min' => 'Selecciona al menos un colaborador.',
            'colaborador_ids.max' => 'No puedes asignar más de 500 colaboradores en una sola operación.',
            'colaborador_ids.*.exists' => 'Uno de los colaboradores seleccionados no es válido.',
            'colaborador_ids.*.distinct' => 'Hay un colaborador repetido en la selección.',
            'motivo.max' => 'El motivo no puede superar los 500 caracteres.',
        ];
    }
}
