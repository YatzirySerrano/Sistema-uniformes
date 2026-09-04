<?php

namespace App\Http\Requests\Entregas;

use App\Models\Activo;
use App\Models\Colaborador;
use App\Models\Conjunto;
use App\Models\EntregaUniforme;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Alta de entrega. La empresa y la sucursal se DERIVAN del colaborador
 * seleccionado; el almacén de origen se elige explícitamente (debe abastecer
 * a esa empresa y estar activo). La entrega combina, en cualquier mezcla:
 * activos sueltos por cantidad+variante, unidades de seguimiento individual
 * elegidas explícitamente, y conjuntos (que expanden a sus componentes reales
 * — el stock se valida por componente, nunca "stock del conjunto").
 */
class GuardarEntregaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', EntregaUniforme::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $colaborador = Colaborador::query()->find($this->integer('colaborador_id'));
        $empresaId = $colaborador?->empresa_id;

        if ($colaborador === null || ! $this->user()?->puedeAccederEmpresa($empresaId)) {
            return ['colaborador_id' => ['required', 'integer', 'exists:colaboradores,id']];
        }

        return [
            'colaborador_id' => [
                'required', 'integer',
                Rule::exists('colaboradores', 'id')->where(fn ($q) => $q->where('empresa_id', $empresaId)->where('activo', true)),
            ],
            'almacen_id' => [
                'required', 'integer',
                Rule::exists('almacen_empresa', 'almacen_id')->where(fn ($q) => $q->where('empresa_id', $empresaId)),
            ],
            'fecha_entrega' => ['required', 'date', 'before_or_equal:today'],
            'notas' => ['nullable', 'string', 'max:1000'],

            'activos' => ['nullable', 'array'],
            'activos.*.activo_id' => [
                'required', 'integer',
                Rule::exists('activos', 'id')->where(fn ($q) => $q->where('empresa_id', $empresaId)->where('tipo_control', 'cantidad')),
            ],
            'activos.*.talla_id' => ['nullable', 'integer', Rule::exists('tallas', 'id')->where(fn ($q) => $q->where('activa', true))],
            'activos.*.cantidad' => ['required', 'integer', 'min:1', 'max:1000'],

            'unidades' => ['nullable', 'array'],
            'unidades.*.unidad_activo_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('unidades_activo', 'id')->where(fn ($q) => $q->where('empresa_id', $empresaId)),
            ],

            'conjuntos' => ['nullable', 'array'],
            'conjuntos.*.conjunto_id' => [
                'required', 'integer',
                Rule::exists('conjuntos', 'id')->where(fn ($q) => $q->where('empresa_id', $empresaId)->where('activo', true)),
            ],
            'conjuntos.*.cantidad' => ['required', 'integer', 'min:1', 'max:100'],
            'conjuntos.*.variantes' => ['nullable', 'array'],
            'conjuntos.*.variantes.*' => ['nullable', 'integer', Rule::exists('tallas', 'id')->where(fn ($q) => $q->where('activa', true))],
        ];
    }

    /**
     * Reglas que no se expresan bien con `Rule::exists`: al menos un renglón
     * en total, y coherencia de variante por componente de conjunto (fija /
     * libre / ninguna — igual que en `GuardarConjuntoRequest`).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $activos = is_array($this->input('activos')) ? $this->input('activos') : [];
            $unidades = is_array($this->input('unidades')) ? $this->input('unidades') : [];
            $conjuntos = is_array($this->input('conjuntos')) ? $this->input('conjuntos') : [];

            if ($activos === [] && $unidades === [] && $conjuntos === []) {
                $validator->errors()->add('items', 'Agrega al menos un activo, unidad o conjunto a la entrega.');

                return;
            }

            $colaborador = Colaborador::query()->find($this->integer('colaborador_id'));
            if ($colaborador === null) {
                return;
            }
            $empresaId = $colaborador->empresa_id;

            $activoIdsSueltos = collect($activos)->pluck('activo_id')->filter()->map(fn ($id) => (int) $id)->unique();
            $activosSueltos = Activo::query()->withCount('tallas')->whereIn('id', $activoIdsSueltos)->get()->keyBy('id');

            foreach ($activos as $i => $fila) {
                $activo = $activosSueltos->get((int) ($fila['activo_id'] ?? 0));
                if ($activo === null) {
                    continue; // ya lo marcó la regla `exists`
                }

                $tallaId = ($fila['talla_id'] ?? null) !== null ? (int) $fila['talla_id'] : null;
                $elegibles = (int) $activo->tallas_count > 0 ? $activo->tallasElegibles()->pluck('id')->all() : [];

                if ($elegibles !== [] && $tallaId === null) {
                    $validator->errors()->add("activos.{$i}.talla_id", 'Selecciona la variante / talla.');
                } elseif ($elegibles !== [] && ! in_array($tallaId, $elegibles, true)) {
                    $validator->errors()->add("activos.{$i}.talla_id", 'Esa variante no corresponde al activo o está desactivada.');
                } elseif ($elegibles === [] && $tallaId !== null) {
                    $validator->errors()->add("activos.{$i}.talla_id", 'Este activo no utiliza variantes.');
                }
            }

            $conjuntoIds = collect($conjuntos)->pluck('conjunto_id')->filter()->map(fn ($id) => (int) $id)->unique();
            $conjuntosCargados = Conjunto::query()->with('componentes')->whereIn('id', $conjuntoIds)->get()->keyBy('id');

            foreach ($conjuntos as $i => $fila) {
                $conjunto = $conjuntosCargados->get((int) ($fila['conjunto_id'] ?? 0));
                if ($conjunto === null) {
                    continue; // ya lo marcó la regla `exists`
                }

                $variantesElegidas = is_array($fila['variantes'] ?? null) ? $fila['variantes'] : [];
                $activoIds = $conjunto->componentes->pluck('activo_id')->unique();
                $activosDelConjunto = Activo::query()->withCount('tallas')->whereIn('id', $activoIds)->get()->keyBy('id');

                foreach ($conjunto->componentes as $componente) {
                    if (! $componente->talla_libre) {
                        continue; // fija o sin variante: no depende de lo enviado aquí
                    }

                    $activoComponente = $activosDelConjunto->get($componente->activo_id);
                    if ($activoComponente === null || (int) $activoComponente->tallas_count === 0) {
                        continue;
                    }

                    $tallaId = $variantesElegidas[$componente->id] ?? null;
                    if ($tallaId === null) {
                        $validator->errors()->add("conjuntos.{$i}.variantes.{$componente->id}", 'Elige la variante para este componente del conjunto.');

                        continue;
                    }

                    if (! $activoComponente->tallasElegibles()->pluck('id')->contains((int) $tallaId)) {
                        $validator->errors()->add("conjuntos.{$i}.variantes.{$componente->id}", 'Esa variante no corresponde al activo o está desactivada.');
                    }
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
            'almacen_id.required' => 'Selecciona el almacén de origen.',
            'almacen_id.exists' => 'El almacén seleccionado no abastece a la empresa del colaborador.',
            'fecha_entrega.before_or_equal' => 'La fecha de entrega no puede ser futura.',
            'colaborador_id.exists' => 'El colaborador seleccionado no es válido o no tienes acceso a su empresa.',
            'unidades.*.unidad_activo_id.distinct' => 'No puedes elegir la misma unidad dos veces.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'activos.*.cantidad' => 'cantidad',
            'activos.*.activo_id' => 'activo',
            'activos.*.talla_id' => 'talla',
            'unidades.*.unidad_activo_id' => 'unidad',
            'conjuntos.*.conjunto_id' => 'conjunto',
        ];
    }
}
