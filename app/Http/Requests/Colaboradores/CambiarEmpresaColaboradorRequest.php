<?php

namespace App\Http\Requests\Colaboradores;

use App\Http\Requests\Concerns\NormalizaEntrada;
use App\Models\Colaborador;
use App\Soporte\AccesoEmpresa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Transfiere a un colaborador a otra empresa / razón social. Es una operación
 * DISTINTA del cambio de servicio (misma empresa): exige empresa + sucursal
 * destino, un motivo, y que el usuario tenga acceso a la empresa ORIGEN y a la
 * DESTINO. Nunca se confía en `empresa_origen_id` del cliente — la origen la
 * fija el registro bloqueado dentro de la acción.
 */
class CambiarEmpresaColaboradorRequest extends FormRequest
{
    use NormalizaEntrada;

    public function authorize(): bool
    {
        $colaborador = $this->route('colaborador');
        $usuario = $this->user();

        if (! $colaborador instanceof Colaborador || $usuario === null) {
            return false;
        }

        // Permiso + acceso a la empresa ORIGEN (Policy) + acceso a la empresa
        // DESTINO: transferir entre razones sociales exige que el usuario opere
        // ambas. Falta de acceso al destino ⇒ 403, no error de validación.
        if (! $usuario->can('cambiarEmpresa', $colaborador)) {
            return false;
        }

        $empresaDestinoId = (int) $this->input('empresa_destino_id');

        return $empresaDestinoId <= 0 || $usuario->puedeAccederEmpresa($empresaDestinoId);
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
        $empresaDestinoId = (int) $this->input('empresa_destino_id');

        return [
            'empresa_destino_id' => [
                'required', 'integer',
                Rule::exists('empresas', 'id')->where('activa', true),
            ],
            'sucursal_destino_id' => [
                'required', 'integer',
                Rule::exists('sucursales', 'id')->where(fn ($q) => $q
                    ->where('empresa_id', $empresaDestinoId)
                    ->where('activa', true)),
            ],
            'area_destino_id' => [
                'nullable', 'integer',
                Rule::exists('areas', 'id')->where(fn ($q) => $q
                    ->where('empresa_id', $empresaDestinoId)
                    ->where('activa', true)),
            ],
            'motivo' => ['required', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Colaborador $colaborador */
            $colaborador = $this->route('colaborador');
            $usuario = $this->user();
            $empresaDestinoId = (int) $this->input('empresa_destino_id');

            if ($empresaDestinoId === (int) $colaborador->empresa_id) {
                $validator->errors()->add('empresa_destino_id', 'El colaborador ya pertenece a esa empresa.');

                return;
            }

            if ($usuario === null || $validator->errors()->hasAny(['sucursal_destino_id'])) {
                return;
            }

            // La sucursal de destino debe estar dentro del alcance de sucursales
            // del usuario en la empresa de destino (roles restringidos).
            $sucursalDestinoId = (int) $this->input('sucursal_destino_id');
            $sucursalesOk = app(AccesoEmpresa::class)
                ->sucursalesAutorizadas($usuario, $empresaDestinoId)
                ->pluck('id');

            if (! $sucursalesOk->contains($sucursalDestinoId)) {
                $validator->errors()->add('sucursal_destino_id', 'No tienes acceso a la sucursal de destino seleccionada.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'empresa_destino_id.required' => 'Selecciona la empresa de destino.',
            'empresa_destino_id.exists' => 'La empresa de destino no es válida o está inactiva.',
            'sucursal_destino_id.required' => 'Selecciona la sucursal de destino.',
            'sucursal_destino_id.exists' => 'La sucursal de destino no pertenece a la empresa elegida o está inactiva.',
            'area_destino_id.exists' => 'El área de destino no pertenece a la empresa elegida o está inactiva.',
            'motivo.required' => 'Indica el motivo de la transferencia.',
            'motivo.max' => 'El motivo no puede superar los 500 caracteres.',
        ];
    }
}
