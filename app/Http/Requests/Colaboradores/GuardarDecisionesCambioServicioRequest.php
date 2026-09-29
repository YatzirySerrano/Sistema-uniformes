<?php

namespace App\Http\Requests\Colaboradores;

use App\Models\CambioServicioColaborador;
use App\Models\Colaborador;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Decisiones de la revisión de custodia de un cambio de servicio. Nunca se
 * confía en lo que muestre el formulario: los renglones deben ser de ESTA
 * revisión, el reparto se revalida bajo lock en la acción y el destinatario
 * de una redistribución debe ser un colaborador activo de la misma empresa,
 * distinto del revisado y dentro del alcance (empresa + sucursal) del usuario.
 */
class GuardarDecisionesCambioServicioRequest extends FormRequest
{
    public function authorize(): bool
    {
        $cambio = $this->route('cambio');

        return $cambio instanceof CambioServicioColaborador
            && ($this->user()?->can('gestionar', $cambio) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var CambioServicioColaborador $cambio */
        $cambio = $this->route('cambio');

        return [
            'renglones' => ['required', 'array'],
            'renglones.*.mantener' => ['required', 'integer', 'min:0'],
            'renglones.*.devolver' => ['required', 'integer', 'min:0'],
            'renglones.*.redistribuir' => ['required', 'integer', 'min:0'],
            'renglones.*.destinatario_id' => [
                'nullable', 'integer',
                Rule::exists('colaboradores', 'id')->where(fn ($q) => $q->where('empresa_id', $cambio->empresa_id)->where('activo', true)->whereNull('deleted_at')),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var CambioServicioColaborador $cambio */
            $cambio = $this->route('cambio');
            $renglones = $cambio->renglones()->get()->keyBy('id');
            $usuario = $this->user();

            foreach ((array) $this->input('renglones', []) as $id => $decision) {
                $renglon = $renglones->get((int) $id);

                if ($renglon === null) {
                    $validator->errors()->add("renglones.{$id}", 'Ese bien no pertenece a esta revisión.');

                    continue;
                }

                $suma = (int) ($decision['mantener'] ?? 0) + (int) ($decision['devolver'] ?? 0) + (int) ($decision['redistribuir'] ?? 0);

                if ($suma !== $renglon->cantidad_revisada) {
                    $validator->errors()->add("renglones.{$id}.mantener", "Reparte exactamente {$renglon->cantidad_revisada} entre mantener, devolver y redistribuir.");

                    continue;
                }

                if ((int) ($decision['redistribuir'] ?? 0) === 0 || $validator->errors()->has("renglones.{$id}.destinatario_id")) {
                    continue;
                }

                $destinatario = ($decision['destinatario_id'] ?? null) !== null
                    ? Colaborador::query()->with('sucursal')->find((int) $decision['destinatario_id'])
                    : null;

                if ($destinatario === null) {
                    $validator->errors()->add("renglones.{$id}.destinatario_id", 'Elige a quién se redistribuye.');
                } elseif ($destinatario->getKey() === $cambio->colaborador_id) {
                    $validator->errors()->add("renglones.{$id}.destinatario_id", 'No puedes redistribuir al mismo colaborador.');
                } elseif ($destinatario->sucursal === null || ! ($usuario?->puedeAccederSucursal($destinatario->sucursal) ?? false)) {
                    $validator->errors()->add("renglones.{$id}.destinatario_id", 'No tienes acceso a la sucursal de ese colaborador.');
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
            'renglones.required' => 'No hay decisiones que guardar.',
            'renglones.*.destinatario_id.exists' => 'El colaborador elegido no es válido, está inactivo o es de otra empresa.',
            'renglones.*.*.min' => 'La cantidad no puede ser negativa.',
        ];
    }
}
