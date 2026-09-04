<?php

namespace App\Http\Requests\Conjuntos;

use App\Http\Requests\Concerns\ResuelveEmpresa;
use App\Models\Activo;
use App\Models\Conjunto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Alta / edición de un Conjunto. En alta la empresa llega en `empresa_id`; en
 * edición queda fijada por el registro. Cada componente debe pertenecer a la
 * MISMA empresa que el conjunto — se valida aquí porque no hay FK que lo
 * garantice (los activos de otra empresa simplemente no existen dentro del
 * alcance validado).
 */
class GuardarConjuntoRequest extends FormRequest
{
    use ResuelveEmpresa;

    public function authorize(): bool
    {
        $conjunto = $this->route('conjunto');

        return $conjunto instanceof Conjunto
            ? ($this->user()?->can('update', $conjunto) ?? false)
            : ($this->user()?->can('create', Conjunto::class) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $empresaId = $this->empresaResuelta('conjunto')->getKey();
        $conjunto = $this->route('conjunto');
        $conjuntoId = $conjunto instanceof Conjunto ? $conjunto->getKey() : null;

        return [
            ...($conjuntoId === null ? ['empresa_id' => ['required', 'integer']] : []),
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'codigo' => [
                'nullable', 'string', 'max:60', 'alpha_dash',
                Rule::unique('conjuntos', 'codigo')
                    ->where(fn ($q) => $q->where('empresa_id', $empresaId))
                    ->ignore($conjuntoId),
            ],
            'activo' => ['boolean'],
            'componentes' => ['required', 'array', 'min:1'],
            'componentes.*.activo_id' => [
                'required', 'integer',
                Rule::exists('activos', 'id')->where(fn ($q) => $q->where('empresa_id', $empresaId)),
            ],
            'componentes.*.cantidad_requerida' => ['required', 'integer', 'min:1', 'max:100'],
            'componentes.*.talla_id' => [
                'nullable', 'integer',
                Rule::exists('tallas', 'id')->where(fn ($q) => $q->where('activa', true)),
            ],
            'componentes.*.talla_libre' => ['boolean'],
        ];
    }

    /**
     * Por componente: la variante (fija o libre) sólo tiene sentido si el
     * activo usa variantes; no se puede fijar Y dejar libre a la vez; si el
     * activo usa variantes y no se marca "libre", la variante fija es
     * obligatoria.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $empresaId = $this->empresaResuelta('conjunto')->getKey();
            $componentes = is_array($this->input('componentes')) ? $this->input('componentes') : [];

            $activoIds = collect($componentes)->pluck('activo_id')->filter()->map(fn ($id) => (int) $id)->unique();
            $activos = Activo::query()->withCount('tallas')->whereIn('id', $activoIds)->get()->keyBy('id');

            foreach ($componentes as $i => $fila) {
                $activo = $activos->get((int) ($fila['activo_id'] ?? 0));

                if ($activo === null) {
                    continue; // ya lo marcó la regla `exists`
                }

                $usaVariantes = (int) $activo->tallas_count > 0;
                $tallaId = ($fila['talla_id'] ?? null) !== null ? (int) $fila['talla_id'] : null;
                $tallaLibre = filter_var($fila['talla_libre'] ?? false, FILTER_VALIDATE_BOOLEAN);

                if (! $usaVariantes) {
                    if ($tallaId !== null || $tallaLibre) {
                        $validator->errors()->add("componentes.{$i}.talla_id", 'Este activo no usa variantes.');
                    }

                    continue;
                }

                if ($tallaId !== null && $tallaLibre) {
                    $validator->errors()->add("componentes.{$i}.talla_id", 'Elige una variante fija o "seleccionar durante la entrega", no ambas.');

                    continue;
                }

                if ($tallaId === null && ! $tallaLibre) {
                    $validator->errors()->add("componentes.{$i}.talla_id", 'Elige la variante o marca "seleccionar durante la entrega".');

                    continue;
                }

                if ($tallaId !== null && ! $activo->tallasElegibles()->pluck('id')->contains($tallaId)) {
                    $validator->errors()->add("componentes.{$i}.talla_id", 'Esa variante no corresponde al activo o está desactivada.');
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
            'empresa_id.required' => 'Selecciona la empresa del conjunto.',
            'nombre.required' => 'El nombre del conjunto es obligatorio.',
            'codigo.unique' => 'Ese código de conjunto ya existe en esta empresa.',
            'componentes.required' => 'Agrega al menos un componente.',
            'componentes.min' => 'Agrega al menos un componente.',
            'componentes.*.activo_id.required' => 'Selecciona el activo.',
            'componentes.*.activo_id.exists' => 'El activo seleccionado no pertenece a esta empresa.',
            'componentes.*.cantidad_requerida.required' => 'Indica la cantidad requerida.',
            'componentes.*.cantidad_requerida.min' => 'La cantidad requerida debe ser mayor a 0.',
        ];
    }
}
