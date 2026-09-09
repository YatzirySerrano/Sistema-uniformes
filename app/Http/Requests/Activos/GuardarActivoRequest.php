<?php

namespace App\Http\Requests\Activos;

use App\Enums\TipoControlActivo;
use App\Http\Requests\Concerns\NormalizaEntrada;
use App\Http\Requests\Concerns\ResuelveEmpresa;
use App\Models\Activo;
use App\Models\CategoriaActivo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

/**
 * Validación de alta y edición de activos. En alta la empresa llega en
 * `empresa_id` y se valida el acceso del usuario; en edición queda fijada por el
 * registro. Las tallas son opcionales (un uniforme las usa; una laptop no).
 */
class GuardarActivoRequest extends FormRequest
{
    use NormalizaEntrada, ResuelveEmpresa;

    public function authorize(): bool
    {
        $activo = $this->route('activo');

        return $activo instanceof Activo
            ? ($this->user()?->can('update', $activo) ?? false)
            : ($this->user()?->can('create', Activo::class) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nombre' => $this->limpiar($this->input('nombre')),
            'descripcion' => $this->limpiar($this->input('descripcion')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $empresaId = $this->empresaResuelta('activo')->getKey();
        $activo = $this->route('activo');
        $activoId = $activo instanceof Activo ? $activo->getKey() : null;
        $esAlta = $activoId === null;

        return [
            ...($esAlta ? ['empresa_id' => ['required', 'integer']] : []),
            ...($esAlta ? [
                // Existencia inicial: se unifica con el alta del Activo. El
                // almacén es sólo el de la ENTRADA INICIAL, no una propiedad
                // permanente del Activo.
                'almacen_id' => [
                    'nullable', 'integer',
                    Rule::exists('almacen_empresa', 'almacen_id')->where(fn ($q) => $q->where('empresa_id', $empresaId)),
                    Rule::exists('almacenes', 'id')->where(fn ($q) => $q->where('activo', true)),
                ],
                'cantidad_inicial' => ['nullable', 'integer', 'min:0', 'max:1000'],
                'existencias' => ['nullable', 'array'],
                'existencias.*.talla_id' => ['required', 'integer'],
                'existencias.*.cantidad' => ['required', 'integer', 'min:0'],
                // Seguimiento individual: NO controla la existencia del QR (que
                // es permanente desde el alta), sólo si al guardar se abre el
                // PDF de etiquetas para imprimirlas ahora.
                'abrir_etiquetas' => ['boolean'],
            ] : []),
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'categoria_id' => [
                'nullable', 'integer',
                Rule::exists('categorias_activo', 'id')->where(fn ($q) => $q->where('activa', true)),
            ],
            // `codigo` NUNCA se valida como entrada del usuario: lo genera
            // el backend (autogenerado, ACT-0001…) en el alta y es
            // inmutable en edición.
            'tipo_activo_id' => [
                'nullable', 'integer',
                Rule::exists('tipos_activo', 'id')->where(fn ($q) => $q->where('activo', true)),
            ],
            'tipo_control' => ['required', new Enum(TipoControlActivo::class)],
            'activo' => ['boolean'],
            'tallas' => ['nullable', 'array'],
            'tallas.*' => [
                'integer',
                Rule::exists('tallas', 'id')->where(fn ($q) => $q->where('activa', true)),
            ],
            'imagen' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            // Bandera explícita "eliminar la imagen actual" (Caso C/E). Sólo
            // aplica en edición; en alta se ignora (no hay imagen previa).
            'eliminar_imagen' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Coherencia tipo ↔ categoría: si el activo lleva tipo y categoría, y la
     * categoría está ligada a un tipo concreto, ambos deben coincidir. Una
     * categoría sin tipo, o un activo sin tipo, no tienen restricción (tipo y
     * categoría son opcionales).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $tipoId = $this->integer('tipo_activo_id') ?: null;
            $categoriaId = $this->integer('categoria_id') ?: null;

            if ($tipoId === null || $categoriaId === null) {
                return;
            }

            // Tipo y categoría son catálogos globales; aquí sólo se valida
            // coherencia entre el tipo y el tipo de la categoría elegida.
            $tipoDeLaCategoria = CategoriaActivo::query()
                ->whereKey($categoriaId)
                ->value('tipo_activo_id');

            if ($tipoDeLaCategoria !== null && (int) $tipoDeLaCategoria !== $tipoId) {
                $validator->errors()->add(
                    'categoria_id',
                    'La categoría seleccionada pertenece a otro tipo de activo. Cámbiala o quita el tipo.',
                );
            }
        });

        if ($this->route('activo') !== null) {
            return; // stock inicial sólo aplica al alta
        }

        $validator->after(function (Validator $validator): void {
            $tallaIds = array_map('intval', $this->input('tallas', []));
            $existencias = is_array($this->input('existencias')) ? $this->input('existencias') : [];
            $cantidadInicial = (int) $this->input('cantidad_inicial', 0);
            $tipoControl = $this->input('tipo_control');

            if ($tallaIds === []) {
                foreach ($existencias as $i => $fila) {
                    $validator->errors()->add("existencias.{$i}.talla_id", 'Este activo no usa variantes; usa la cantidad inicial.');
                }

                return;
            }

            // El activo usa variantes: cada fila de existencia debe referirse
            // a una de las variantes asociadas, sin duplicados.
            $vistos = [];
            foreach ($existencias as $i => $fila) {
                $tallaId = (int) ($fila['talla_id'] ?? 0);

                if (! in_array($tallaId, $tallaIds, true)) {
                    $validator->errors()->add("existencias.{$i}.talla_id", 'La variante indicada no está asociada a este activo.');

                    continue;
                }

                if (isset($vistos[$tallaId])) {
                    $validator->errors()->add("existencias.{$i}.talla_id", 'Esa variante ya tiene una cantidad inicial capturada.');

                    continue;
                }

                $vistos[$tallaId] = true;
            }

            if ($tipoControl === TipoControlActivo::Cantidad->value && $cantidadInicial > 0) {
                $validator->errors()->add('cantidad_inicial', 'Este activo usa variantes; captura la cantidad por variante.');
            }
        });

        $validator->after(function (Validator $validator): void {
            $tipoControl = $this->input('tipo_control');
            $almacenId = $this->integer('almacen_id') ?: null;
            $cantidadInicial = (int) $this->input('cantidad_inicial', 0);
            $existencias = is_array($this->input('existencias')) ? $this->input('existencias') : [];
            $hayExistenciaConCantidad = $cantidadInicial > 0
                || collect($existencias)->contains(fn ($f) => (int) ($f['cantidad'] ?? 0) > 0);

            $necesitaAlmacen = ($tipoControl === TipoControlActivo::Cantidad->value && $hayExistenciaConCantidad)
                || ($tipoControl === TipoControlActivo::SeguimientoIndividual->value && $cantidadInicial > 0);

            if ($necesitaAlmacen && $almacenId === null) {
                $validator->errors()->add('almacen_id', 'Selecciona el almacén donde vas a registrar la existencia inicial.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'empresa_id.required' => 'Selecciona la empresa del activo.',
            'almacen_id.exists' => 'El almacén no abastece a esta empresa o está desactivado.',
            'existencias.*.cantidad.min' => 'La cantidad no puede ser negativa.',
            'nombre.required' => 'El nombre del activo es obligatorio.',
            'nombre.max' => 'El nombre no puede superar los 255 caracteres.',
            'tipo_activo_id.exists' => 'El tipo de activo seleccionado no existe o está desactivado.',
            'categoria_id.exists' => 'La categoría seleccionada no existe o está desactivada.',
            'tipo_control.enum' => 'El tipo de control debe ser "por cantidad" o "seguimiento individual".',
            'tipo_control.required' => 'Indica cómo se controla el activo.',
            'tallas.*.exists' => 'Una de las variantes / tallas seleccionadas no existe o está desactivada.',
            'imagen.image' => 'El archivo debe ser una imagen.',
            'imagen.mimes' => 'La imagen debe ser JPG, PNG o WebP.',
            'imagen.max' => 'La imagen no puede superar los 4 MB.',
        ];
    }
}
