<?php

namespace App\Http\Requests\Activos;

use App\Models\Activo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * "Agregar existencias" desde el detalle/edición de un Activo (fuera del
 * contexto del alta). El almacén sigue siendo sólo el de ESTA entrada, nunca
 * una propiedad permanente del Activo. Internamente reutiliza
 * `RegistrarEntradaInventario`, igual que "Registrar entrada" e igual que el
 * alta unificada.
 */
class AgregarExistenciasRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Activo $activo */
        $activo = $this->route('activo');

        return $this->user()->can('inventario.entrada') && $this->user()->can('update', $activo);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Activo $activo */
        $activo = $this->route('activo');
        $empresaId = $activo->empresa_id;

        return [
            'almacen_id' => [
                'required', 'integer',
                Rule::exists('almacen_empresa', 'almacen_id')->where(fn ($q) => $q->where('empresa_id', $empresaId)),
                Rule::exists('almacenes', 'id')->where(fn ($q) => $q->where('activo', true)),
            ],
            'talla_id' => [
                'nullable', 'integer',
                Rule::exists('tallas', 'id')->where(fn ($q) => $q->where('activa', true)),
            ],
            'cantidad' => ['required', 'integer', 'min:1', 'max:100000'],
            'motivo' => ['nullable', 'string', 'max:255'],
            // No controla la existencia del QR (permanente desde el alta), sólo
            // si al guardar se abre el PDF de etiquetas para imprimirlas ahora.
            'abrir_etiquetas' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Activo $activo */
            $activo = $this->route('activo');
            $tallaId = $this->integer('talla_id') ?: null;
            $elegibles = $activo->tallasElegibles()->pluck('id')->all();

            if ($elegibles !== [] && $tallaId === null) {
                $validator->errors()->add('talla_id', 'Selecciona la variante / talla.');
            } elseif ($elegibles === [] && $tallaId !== null) {
                $validator->errors()->add('talla_id', 'Este activo no utiliza variantes.');
            } elseif ($tallaId !== null && ! in_array($tallaId, $elegibles, true)) {
                $validator->errors()->add('talla_id', 'Esa variante no corresponde al activo o está desactivada.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'almacen_id.required' => 'Selecciona el almacén donde vas a registrar la existencia.',
            'almacen_id.exists' => 'El almacén no abastece a esta empresa o está desactivado.',
            'cantidad.required' => 'Ingresa una cantidad mayor a 0.',
            'cantidad.min' => 'Ingresa una cantidad mayor a 0.',
        ];
    }
}
