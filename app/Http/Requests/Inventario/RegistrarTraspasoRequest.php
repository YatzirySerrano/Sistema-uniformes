<?php

namespace App\Http\Requests\Inventario;

use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoUnidadActivo;
use App\Enums\TipoControlActivo;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\SaldoInventario;
use App\Models\Talla;
use App\Models\UnidadActivo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Alta de un traspaso de inventario (misma empresa o interempresa), con
 * encabezado + varios renglones. TODO se revalida contra la BD: manipular los
 * ids desde el navegador nunca debe permitir sacar stock de una empresa a la
 * que el usuario no tiene acceso, ni de un almacén que no abastece a esa
 * empresa, ni de unidades que no están realmente disponibles.
 */
class RegistrarTraspasoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventario.transferir') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'empresa_origen_id' => ['required', 'integer'],
            'almacen_origen_id' => ['required', 'integer'],
            'empresa_destino_id' => ['required', 'integer'],
            'almacen_destino_id' => ['required', 'integer'],
            'motivo' => ['nullable', 'string', 'max:255'],
            'notas' => ['nullable', 'string', 'max:1000'],

            'renglones' => ['required', 'array', 'min:1', 'max:100'],
            'renglones.*.control' => ['required', Rule::in([TipoControlActivo::Cantidad->value, TipoControlActivo::SeguimientoIndividual->value])],
            'renglones.*.activo_origen_id' => ['required', 'integer'],
            'renglones.*.activo_destino_id' => ['nullable', 'integer'],
            'renglones.*.talla_id' => ['nullable', 'integer'],
            'renglones.*.cantidad' => ['nullable', 'integer', 'min:1', 'max:100000', 'required_if:renglones.*.control,cantidad'],
            'renglones.*.unidad_ids' => ['nullable', 'array', 'required_if:renglones.*.control,individual'],
            'renglones.*.unidad_ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * Blindaje multiempresa + coherencia de renglones. Nada de esto se confía
     * al frontend.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $usuario = $this->user();
            $empresaOrigenId = $this->integer('empresa_origen_id');
            $empresaDestinoId = $this->integer('empresa_destino_id');
            $almacenOrigenId = $this->integer('almacen_origen_id');
            $almacenDestinoId = $this->integer('almacen_destino_id');

            // El usuario debe poder acceder a AMBAS empresas (origen y destino).
            if ($usuario === null || ! $usuario->puedeAccederEmpresa($empresaOrigenId)) {
                $validator->errors()->add('empresa_origen_id', 'No tienes acceso a la empresa origen.');
            }
            if ($usuario === null || ! $usuario->puedeAccederEmpresa($empresaDestinoId)) {
                $validator->errors()->add('empresa_destino_id', 'No tienes acceso a la empresa destino.');
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            // Almacenes: cada uno debe abastecer a SU empresa y estar activo.
            if (! $this->almacenValido($almacenOrigenId, $empresaOrigenId)) {
                $validator->errors()->add('almacen_origen_id', 'El almacén origen no abastece a la empresa origen o está desactivado.');
            }
            if (! $this->almacenValido($almacenDestinoId, $empresaDestinoId)) {
                $validator->errors()->add('almacen_destino_id', 'El almacén destino no abastece a la empresa destino o está desactivado.');
            }

            if ($empresaOrigenId === $empresaDestinoId && $almacenOrigenId === $almacenDestinoId) {
                $validator->errors()->add('almacen_destino_id', 'En un traspaso dentro de la misma empresa el almacén destino debe ser distinto del origen.');
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var array<int, array<string, mixed>> $renglones */
            $renglones = $this->input('renglones', []);

            foreach ($renglones as $i => $renglon) {
                $control = TipoControlActivo::tryFrom((string) ($renglon['control'] ?? ''));
                $activoOrigen = Activo::query()->where('empresa_id', $empresaOrigenId)->find((int) ($renglon['activo_origen_id'] ?? 0));

                if ($activoOrigen === null) {
                    $validator->errors()->add("renglones.{$i}.activo_origen_id", 'Este activo no pertenece a la empresa origen.');

                    continue;
                }
                if ($control !== null && $activoOrigen->tipo_control !== $control) {
                    $validator->errors()->add("renglones.{$i}.control", 'El tipo de control no coincide con el del activo.');

                    continue;
                }

                if (($renglon['activo_destino_id'] ?? null) !== null) {
                    $destino = Activo::query()->where('empresa_id', $empresaDestinoId)->find((int) $renglon['activo_destino_id']);
                    if ($destino === null || $destino->tipo_control !== $activoOrigen->tipo_control) {
                        $validator->errors()->add("renglones.{$i}.activo_destino_id", 'El activo destino elegido no pertenece a la empresa destino o no es del mismo tipo de control.');
                    }
                }

                if ($control === TipoControlActivo::Cantidad) {
                    $tallaId = ($renglon['talla_id'] ?? null) !== null ? (int) $renglon['talla_id'] : null;
                    $elegibles = $activoOrigen->tallasElegibles()->pluck('id')->all();

                    if ($elegibles !== [] && ($tallaId === null || ! in_array($tallaId, $elegibles, true))) {
                        $validator->errors()->add("renglones.{$i}.talla_id", 'Selecciona una variante válida para este activo.');

                        continue;
                    }
                    if ($elegibles === [] && $tallaId !== null) {
                        $validator->errors()->add("renglones.{$i}.talla_id", 'Este activo no utiliza variantes.');

                        continue;
                    }

                    // Pre-chequeo de stock POR VARIANTE (no bloqueante: la
                    // autoridad final es la transacción con lockForUpdate). Se
                    // consulta el saldo EXACTO de (empresa+almacén+activo+talla),
                    // nunca el agregado del activo.
                    $tallaFinal = $elegibles === [] ? null : $tallaId;
                    $cantidad = (int) ($renglon['cantidad'] ?? 0);
                    $saldo = SaldoInventario::query()
                        ->where('empresa_id', $empresaOrigenId)
                        ->where('almacen_id', $almacenOrigenId)
                        ->where('activo_id', $activoOrigen->id)
                        ->when($tallaFinal === null, fn ($q) => $q->whereNull('talla_id'), fn ($q) => $q->where('talla_id', $tallaFinal))
                        ->value('cantidad');
                    $disponible = (int) ($saldo ?? 0);

                    if ($cantidad > $disponible) {
                        $tallaTxt = $tallaFinal === null ? '' : ' (variante '.Talla::query()->whereKey($tallaFinal)->value('valor').')';
                        $almacenNombre = Almacen::query()->whereKey($almacenOrigenId)->value('nombre');
                        $validator->errors()->add(
                            "renglones.{$i}.cantidad",
                            "Sólo hay {$disponible} unidades disponibles de {$activoOrigen->nombre}{$tallaTxt} en el almacén {$almacenNombre}.",
                        );
                    }

                    continue;
                }

                // Seguimiento individual: cada unidad debe estar disponible en
                // el almacén origen de esta empresa.
                $unidadIds = array_values(array_unique(array_map('intval', is_array($renglon['unidad_ids'] ?? null) ? $renglon['unidad_ids'] : [])));
                if ($unidadIds === []) {
                    $validator->errors()->add("renglones.{$i}.unidad_ids", 'Selecciona al menos una unidad.');

                    continue;
                }

                $disponibles = UnidadActivo::query()
                    ->whereIn('id', $unidadIds)
                    ->where('empresa_id', $empresaOrigenId)
                    ->where('activo_id', $activoOrigen->id)
                    ->where('almacen_id', $almacenOrigenId)
                    ->where('estado', EstadoUnidadActivo::EnAlmacen->value)
                    ->where('condicion', CondicionUnidadActivo::Funcionando->value)
                    ->pluck('id')
                    ->all();

                if (count($disponibles) !== count($unidadIds)) {
                    $validator->errors()->add("renglones.{$i}.unidad_ids", 'Una o más unidades ya no están disponibles en el almacén origen (fueron asignadas, movidas o dejaron de ser operativas).');
                }
            }
        });
    }

    private function almacenValido(int $almacenId, int $empresaId): bool
    {
        $almacen = Almacen::query()->where('activo', true)->find($almacenId);

        return $almacen !== null && $almacen->abasteceEmpresa($empresaId);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'renglones.required' => 'Agrega al menos un renglón al traspaso.',
            'renglones.*.control.in' => 'Tipo de control no válido.',
            'renglones.*.activo_origen_id.required' => 'Selecciona el activo a traspasar.',
            'renglones.*.cantidad.required_if' => 'Indica la cantidad a traspasar.',
            'renglones.*.unidad_ids.required_if' => 'Selecciona las unidades a traspasar.',
        ];
    }
}
