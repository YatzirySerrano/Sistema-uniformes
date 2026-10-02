<?php

namespace App\Http\Requests\Devoluciones;

use App\Enums\CondicionDevolucion;
use App\Enums\CondicionUnidadActivo;
use App\Http\Requests\Concerns\ValidaFirmaColaborador;
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
    use ValidaFirmaColaborador;

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
            // Sólo para redirigir de vuelta al contexto de pendientes del
            // colaborador (flujo Transferencia → Devoluciones); el backend
            // revalida que coincida con el dueño real de la entrega antes de
            // usarlo — nunca se confía para autorización.
            'colaborador_id' => ['nullable', 'integer'],
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
            // Firma de quien devuelve: dibujada o archivo (firma a distancia).
            ...$this->reglasFirmaColaborador(),
            'firma_operador' => ['required', 'string', 'max:3000000'],
            'aceptacion' => ['accepted'],
            // Token del apartado temporal de custodia armado en el paso 2
            // (ver `App\Acciones\ReservarCustodiaDevolucion`). Opcional por
            // compatibilidad; si viene, `RegistrarDevolucion` la valida y
            // consume — nunca reemplaza sus propias revalidaciones.
            'reserva_token' => ['nullable', 'uuid'],

            'activos' => ['nullable', 'array'],
            'activos.*.detalle_entrega_id' => [
                'required', 'integer',
                Rule::exists('detalles_entrega', 'id')->where(fn ($q) => $q->where('entrega_uniforme_id', $entregaId)->whereNull('unidad_activo_id')),
            ],
            'activos.*.cantidad' => ['required', 'integer', 'min:1'],
            // "Robo / extravío" sólo existe para marcar condición directo
            // desde el stock disponible de un almacén (`MarcarCondicionInventario`)
            // — nunca aplica a una devolución (no se puede "devolver" algo
            // que se reporta como robado o extraviado).
            'activos.*.condicion' => ['required_without:activos.*.condiciones', 'nullable', Rule::enum(CondicionDevolucion::class)->except([CondicionDevolucion::RoboExtravio])],
            // "Dividir por condición": el MISMO renglón repartido entre varias
            // condiciones dentro de esta misma devolución. La suma exacta y
            // las condiciones repetidas se validan en `withValidator()` (por
            // renglón; `distinct` compararía entre renglones distintos).
            'activos.*.condiciones' => ['nullable', 'array', 'max:'.count(CondicionDevolucion::cases())],
            'activos.*.condiciones.*.condicion' => ['required', Rule::enum(CondicionDevolucion::class)->except([CondicionDevolucion::RoboExtravio])],
            'activos.*.condiciones.*.cantidad' => ['required', 'integer', 'min:0'],
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

            foreach ($activos as $i => $fila) {
                $this->validarDistribucionPorCondicion($validator, (int) $i, is_array($fila) ? $fila : []);
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
     * Con desglose, las cantidades por condición deben sumar EXACTAMENTE la
     * cantidad a devolver del renglón, sin repetir condición. Los errores de
     * tipo (no entero, negativo, condición inválida) ya los marcan las reglas.
     *
     * @param  array<string, mixed>  $fila
     */
    private function validarDistribucionPorCondicion(Validator $validator, int $i, array $fila): void
    {
        $desglose = $fila['condiciones'] ?? null;
        if (! is_array($desglose) || $desglose === [] || $validator->errors()->has("activos.{$i}.condiciones.*")) {
            return;
        }

        $condiciones = array_map(fn ($parte): string => (string) (is_array($parte) ? ($parte['condicion'] ?? '') : ''), $desglose);
        if (count($condiciones) !== count(array_unique($condiciones))) {
            $validator->errors()->add("activos.{$i}.condiciones", 'Cada condición sólo puede aparecer una vez en la distribución.');

            return;
        }

        $asignado = array_sum(array_map(fn ($parte): int => is_array($parte) ? (int) ($parte['cantidad'] ?? 0) : 0, $desglose));
        $total = filter_var($fila['cantidad'] ?? null, FILTER_VALIDATE_INT);
        if ($total === false || $asignado === $total) {
            return;
        }

        $diferencia = abs($total - $asignado);
        $piezas = $diferencia === 1 ? '1 pieza' : "{$diferencia} piezas";
        $validator->errors()->add(
            "activos.{$i}.condiciones",
            $asignado < $total
                ? "Falta asignar condición a {$piezas}."
                : "La distribución por condición supera la cantidad a devolver por {$piezas}.",
        );
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
            ...$this->mensajesFirmaColaborador('de quien devuelve'),
            'firma_operador.required' => 'Falta la firma del encargado que recibe la devolución.',
            'aceptacion.accepted' => 'Debes confirmar la aceptación antes de finalizar la devolución.',
            'activos.*.detalle_entrega_id.exists' => 'Ese renglón no pertenece a esta entrega.',
            'activos.*.condicion.required_without' => 'Elige la condición al recibir.',
            'activos.*.condiciones.*.cantidad.integer' => 'Las cantidades por condición deben ser números enteros.',
            'activos.*.condiciones.*.cantidad.min' => 'Las cantidades por condición no pueden ser negativas.',
            'activos.*.condiciones.*.condicion.enum' => 'Esa condición no es válida para una devolución.',
            'unidades.*.detalle_entrega_id.exists' => 'Esa unidad no pertenece a esta entrega.',
            'unidades.*.detalle_entrega_id.distinct' => 'No puedes devolver la misma unidad dos veces en un mismo movimiento.',
        ];
    }
}
