<?php

namespace App\Http\Requests\Devoluciones;

use App\Enums\CondicionDevolucion;
use App\Enums\CondicionUnidadActivo;
use App\Models\Devolucion;
use App\Models\EntregaUniforme;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Alta de devolución. SIEMPRE se origina desde una entrega concreta
 * (`entrega_uniforme_id`): cada renglón referencia el renglón real de esa
 * entrega (`detalle_entrega_id`), nunca activo/talla sueltos — así el backend
 * deriva empresa/sucursal/activo/variante del renglón original y puede
 * acumular cuánto se ha devuelto ya para no exceder lo pendiente.
 */
class GuardarDevolucionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Devolucion::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $entrega = EntregaUniforme::query()->find($this->integer('entrega_uniforme_id'));

        if ($entrega === null || ! $this->user()?->puedeAccederEmpresa($entrega->empresa_id)) {
            return ['entrega_uniforme_id' => ['required', 'integer', 'exists:entregas_uniformes,id']];
        }

        $empresaId = $entrega->empresa_id;
        $entregaId = $entrega->id;

        return [
            'entrega_uniforme_id' => ['required', 'integer'],
            'almacen_id' => [
                'required', 'integer',
                Rule::exists('almacen_empresa', 'almacen_id')->where(fn ($q) => $q->where('empresa_id', $empresaId)),
            ],
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'motivo' => ['nullable', 'string', 'max:255'],
            'notas' => ['nullable', 'string', 'max:1000'],

            // Flujo ÚNICO (wizard): la petición trae SIEMPRE las dos firmas
            // manuscritas y la aceptación. La validez del trazo la revalida
            // `ValidadorFirma` dentro de `ConfirmarAcuseDevolucion`.
            'firma' => ['required', 'string', 'max:3000000'],
            'firma_operador' => ['required', 'string', 'max:3000000'],
            'aceptacion' => ['accepted'],

            'activos' => ['nullable', 'array'],
            'activos.*.detalle_entrega_id' => [
                'required', 'integer',
                Rule::exists('detalles_entrega', 'id')->where(fn ($q) => $q->where('entrega_uniforme_id', $entregaId)->whereNull('unidad_activo_id')),
            ],
            'activos.*.cantidad' => ['required', 'integer', 'min:1'],
            'activos.*.condicion' => ['required', Rule::enum(CondicionDevolucion::class)],
            // Evidencia fotográfica OPCIONAL por renglón devuelto.
            'activos.*.evidencia' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'activos.*.evidencia_origen' => ['nullable', 'in:camara,archivo'],

            'unidades' => ['nullable', 'array'],
            'unidades.*.detalle_entrega_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('detalles_entrega', 'id')->where(fn ($q) => $q->where('entrega_uniforme_id', $entregaId)->whereNotNull('unidad_activo_id')),
            ],
            'unidades.*.condicion' => ['required', Rule::enum(CondicionUnidadActivo::class)],
            'unidades.*.evidencia' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'unidades.*.evidencia_origen' => ['nullable', 'in:camara,archivo'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $activos = is_array($this->input('activos')) ? $this->input('activos') : [];
            $unidades = is_array($this->input('unidades')) ? $this->input('unidades') : [];

            if ($activos === [] && $unidades === []) {
                $validator->errors()->add('items', 'Agrega al menos un renglón a devolver.');
            }

            foreach ($unidades as $i => $fila) {
                $condicion = CondicionUnidadActivo::tryFrom((string) ($fila['condicion'] ?? ''));

                if ($condicion?->esIncidencia()) {
                    $validator->errors()->add("unidades.{$i}.condicion", 'Pérdida o robo no se registra como devolución. Usa "Reportar incidencia" desde la unidad.');
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
            'entrega_uniforme_id.required' => 'Selecciona la entrega de origen.',
            'almacen_id.required' => 'Selecciona el almacén destino.',
            'almacen_id.exists' => 'El almacén seleccionado no abastece a la empresa de esta entrega.',
            'fecha.before_or_equal' => 'La fecha de devolución no puede ser futura.',
            'firma.required' => 'Solicita la firma de quien devuelve para continuar.',
            'firma_operador.required' => 'Falta la firma del encargado que recibe la devolución.',
            'aceptacion.accepted' => 'Debes confirmar la aceptación antes de finalizar la devolución.',
            'activos.*.detalle_entrega_id.exists' => 'Ese renglón no pertenece a esta entrega.',
            'unidades.*.detalle_entrega_id.exists' => 'Esa unidad no pertenece a esta entrega.',
            'unidades.*.detalle_entrega_id.distinct' => 'No puedes devolver la misma unidad dos veces en un mismo movimiento.',
        ];
    }
}
