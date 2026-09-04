<?php

namespace App\Http\Requests\Activos;

use App\Enums\TipoControlActivo;
use App\Http\Requests\Concerns\ResuelveEmpresa;
use App\Models\Activo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validación de "Registrar entrada de inventario". Todos los errores son por
 * campo y, en las filas repetibles, con la clave `items.N.<campo>` para que el
 * frontend los pinte junto a la fila correspondiente.
 *
 * Reglas de negocio de variante (catálogo compartido habilitado por empresa):
 * - Variante elegible = asociada al activo (`activo_talla`) **y** habilitada para
 *   la empresa de la entrada (`talla_empresa`) **y** activa globalmente.
 * - Si el activo tiene ≥ 1 variante elegible → `talla_id` es obligatorio y debe
 *   ser una de ellas.
 * - Si el activo NO tiene variantes asociadas → `talla_id` va nulo (saldo con
 *   `talla_id = NULL` = "sin variante"; ya no hay talla comodín).
 * - Si el activo tiene variantes asociadas pero NINGUNA habilitada para esta
 *   empresa → se rechaza (hay que habilitar una en Variantes / tallas).
 * - Activos serializados: no se registran por esta pantalla (fase posterior).
 * - Filas duplicadas (mismo activo + variante) → se marca la segunda.
 */
class RegistrarEntradaInventarioRequest extends FormRequest
{
    use ResuelveEmpresa;

    public function authorize(): bool
    {
        return $this->user()?->can('inventario.entrada') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $empresaId = $this->empresaResuelta()->getKey();

        return [
            'empresa_id' => ['required', 'integer'],
            'almacen_id' => [
                'required', 'integer',
                Rule::exists('almacen_empresa', 'almacen_id')->where(fn ($q) => $q->where('empresa_id', $empresaId)),
                Rule::exists('almacenes', 'id')->where(fn ($q) => $q->where('activo', true)),
            ],
            'motivo' => ['required', 'string', 'max:255'],
            'notas' => ['nullable', 'string', 'max:1000'],
            'carga_inicial' => ['boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.activo_id' => [
                'required', 'integer',
                Rule::exists('activos', 'id')->where(fn ($q) => $q->where('empresa_id', $empresaId)->where('activo', true)),
            ],
            'items.*.talla_id' => [
                'nullable', 'integer',
                Rule::exists('talla_empresa', 'talla_id')->where(fn ($q) => $q->where('empresa_id', $empresaId)),
            ],
            'items.*.cantidad' => ['required', 'integer', 'min:1', 'max:100000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'empresa_id.required' => 'Selecciona la empresa.',
            'almacen_id.required' => 'Selecciona el almacén de destino.',
            'almacen_id.exists' => 'El almacén no abastece a esta empresa o está desactivado.',
            'motivo.required' => 'Indica el motivo o la referencia de la entrada.',
            'items.required' => 'Agrega al menos un activo.',
            'items.min' => 'Agrega al menos un activo.',
            'items.*.activo_id.required' => 'Selecciona el activo.',
            'items.*.activo_id.exists' => 'El activo seleccionado no pertenece a esta empresa o está inactivo.',
            'items.*.talla_id.exists' => 'La variante seleccionada no pertenece a esta empresa.',
            'items.*.cantidad.required' => 'Ingresa una cantidad mayor a 0.',
            'items.*.cantidad.min' => 'Ingresa una cantidad mayor a 0.',
            'items.*.cantidad.integer' => 'La cantidad debe ser un número entero.',
        ];
    }

    protected function passedValidation(): void
    {
        $raw = $this->input('items');
        $items = is_array($raw) ? $raw : [];

        $normalizados = array_map(static function (mixed $item): mixed {
            if (! is_array($item)) {
                return $item;
            }
            $item['cantidad'] = (int) ($item['cantidad'] ?? 0);
            $item['talla_id'] = ($item['talla_id'] ?? null) ?: null;

            return $item;
        }, $items);

        $this->merge(['items' => $normalizados]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $empresaId = $this->empresaResuelta()->getKey();
            $raw = $this->input('items');
            $items = is_array($raw) ? $raw : [];

            $activoIds = [];
            foreach ($items as $item) {
                if (is_array($item) && isset($item['activo_id'])) {
                    $activoIds[] = (int) $item['activo_id'];
                }
            }

            $activos = Activo::query()
                ->withCount('tallas')
                ->whereIn('id', array_values(array_unique($activoIds)))
                ->get()
                ->keyBy('id');

            $vistos = [];

            foreach ($items as $i => $item) {
                if (! is_array($item)) {
                    continue;
                }

                $activo = $activos->get((int) ($item['activo_id'] ?? 0));

                if ($activo === null) {
                    continue; // ya lo marcó la regla `exists`
                }

                if ($activo->tipo_control === TipoControlActivo::Serializado) {
                    $validator->errors()->add(
                        "items.{$i}.activo_id",
                        'Los activos serializados se registran unidad por unidad (próxima fase), no por esta pantalla.'
                    );

                    continue;
                }

                $tallaId = ($item['talla_id'] ?? null) !== null ? (int) $item['talla_id'] : null;
                $habilitadas = (int) $activo->tallas_count > 0
                    ? $activo->tallasHabilitadas($empresaId)->pluck('id')->all()
                    : [];

                if ((int) $activo->tallas_count > 0 && $habilitadas === []) {
                    $validator->errors()->add(
                        "items.{$i}.activo_id",
                        'Este activo usa variantes pero ninguna está habilitada para esta empresa. Habilita una en Variantes / tallas antes de registrar existencias.'
                    );
                } elseif ($habilitadas !== []) {
                    if ($tallaId === null) {
                        $validator->errors()->add("items.{$i}.talla_id", 'Selecciona la variante / talla.');
                    } elseif (! in_array($tallaId, $habilitadas, true)) {
                        $validator->errors()->add("items.{$i}.talla_id", 'Esa variante no está habilitada para esta empresa o no corresponde al activo.');
                    }
                } elseif ($tallaId !== null) {
                    $validator->errors()->add("items.{$i}.talla_id", 'Este activo no utiliza variantes.');
                }

                $clave = ($item['activo_id'] ?? '').'-'.($tallaId ?? '0');
                if (isset($vistos[$clave])) {
                    $validator->errors()->add("items.{$i}.activo_id", 'Ya agregaste este activo y variante. Ajusta la cantidad en la fila anterior.');
                } else {
                    $vistos[$clave] = true;
                }
            }
        });
    }
}
