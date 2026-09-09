<?php

namespace App\Http\Requests\Entregas;

use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoUnidadActivo;
use App\Models\Activo;
use App\Models\Colaborador;
use App\Models\Conjunto;
use App\Models\EntregaUniforme;
use App\Models\SaldoInventario;
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
 *
 * Flujo ÚNICO: la petición trae SIEMPRE las dos firmas manuscritas y la
 * aceptación. No existe un alta "pendiente de firma"; sin firmas la
 * validación falla y no se registra ni descuenta nada.
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
            // El servicio de DESTINO de la entrega NO lo elige el frontend: se
            // deriva SIEMPRE del servicio operativo vigente del colaborador
            // (`servicio_actual_id`) en el controlador, y se guarda como
            // snapshot histórico. Cualquier `servicio_id` que venga en el body
            // se ignora. Aquí sólo se valida (en `withValidator`) que ese
            // servicio vigente, si existe, siga operativo.
            'fecha_entrega' => ['required', 'date', 'before_or_equal:today'],
            'notas' => ['nullable', 'string', 'max:1000'],

            // Firmas de AMBAS partes + aceptación: parte inseparable del alta.
            'firma' => ['required', 'string', 'max:3000000'],
            'firma_operador' => ['required', 'string', 'max:3000000'],
            'aceptacion' => ['accepted'],
            // Idempotencia opcional generada por el formulario: evita que un
            // doble submit / reintento de red registre dos entregas.
            'idempotency_key' => ['nullable', 'uuid'],

            'activos' => ['nullable', 'array'],
            'activos.*.activo_id' => [
                'required', 'integer',
                Rule::exists('activos', 'id')->where(fn ($q) => $q->where('empresa_id', $empresaId)->where('tipo_control', 'cantidad')->where('activo', true)),
            ],
            'activos.*.talla_id' => ['nullable', 'integer', Rule::exists('tallas', 'id')->where(fn ($q) => $q->where('activa', true))],
            'activos.*.cantidad' => ['required', 'integer', 'min:1', 'max:1000'],

            'unidades' => ['nullable', 'array'],
            'unidades.*.unidad_activo_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('unidades_activo', 'id')->where(fn ($q) => $q
                    ->where('empresa_id', $empresaId)
                    ->where('almacen_id', $this->integer('almacen_id'))
                    ->where('estado', EstadoUnidadActivo::EnAlmacen->value)
                    ->where('condicion', CondicionUnidadActivo::Funcionando->value)),
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
                $validator->errors()->add('items', 'Agrega al menos un activo, unidad identificada o conjunto a la entrega.');

                return;
            }

            $colaborador = Colaborador::query()->find($this->integer('colaborador_id'));
            if ($colaborador === null) {
                return;
            }
            $empresaId = $colaborador->empresa_id;
            $almacenId = $this->integer('almacen_id');

            // El snapshot de servicio se deriva del servicio operativo VIGENTE
            // del colaborador. Si tiene uno pero quedó inactivo (él o su
            // contrato), no se puede registrar una entrega operativa: hay que
            // reasignar al colaborador a un servicio activo primero.
            $colaborador->loadMissing('servicioActual.contrato');
            $servicioActual = $colaborador->servicioActual;

            if ($servicioActual !== null && (! $servicioActual->activo || ! $servicioActual->contrato->activo)) {
                $validator->errors()->add(
                    'servicio_id',
                    'El servicio operativo vigente de este colaborador está inactivo (o su contrato). Reasígnalo a un servicio activo desde su ficha antes de registrar la entrega.',
                );
            }

            $activoIdsSueltos = collect($activos)->pluck('activo_id')->filter()->map(fn ($id) => (int) $id)->unique();
            $activosSueltos = Activo::query()->withCount('tallas')->whereIn('id', $activoIdsSueltos)->get()->keyBy('id');

            // Saldo real de cada activo+talla EN EL ALMACÉN elegido (nunca el
            // total de otro almacén ni de otra empresa) y cantidad total
            // solicitada por esa misma combinación (varias filas pueden pedir
            // el mismo activo+talla) — mismo criterio de consolidación que
            // `CrearEntregaUniforme::consolidarActivos()`.
            $saldosPorClave = SaldoInventario::query()
                ->where('empresa_id', $empresaId)
                ->where('almacen_id', $almacenId)
                ->whereIn('activo_id', $activoIdsSueltos)
                ->get()
                ->keyBy(fn (SaldoInventario $s): string => $s->activo_id.'-'.($s->talla_id ?? '0'));

            $solicitadoPorClave = [];
            foreach ($activos as $fila) {
                $activoId = (int) ($fila['activo_id'] ?? 0);
                if (! $activosSueltos->has($activoId)) {
                    continue;
                }
                $tallaId = ($fila['talla_id'] ?? null) !== null ? (int) $fila['talla_id'] : null;
                $clave = $activoId.'-'.($tallaId ?? '0');
                $solicitadoPorClave[$clave] = ($solicitadoPorClave[$clave] ?? 0) + (int) ($fila['cantidad'] ?? 0);
            }

            foreach ($activos as $i => $fila) {
                $activo = $activosSueltos->get((int) ($fila['activo_id'] ?? 0));
                if ($activo === null) {
                    continue; // ya lo marcó la regla `exists`
                }

                $tallaId = ($fila['talla_id'] ?? null) !== null ? (int) $fila['talla_id'] : null;
                $elegibles = (int) $activo->tallas_count > 0 ? $activo->tallasElegibles()->pluck('id')->all() : [];

                if ($elegibles !== [] && $tallaId === null) {
                    $validator->errors()->add("activos.{$i}.talla_id", 'Selecciona la variante / talla.');

                    continue;
                } elseif ($elegibles !== [] && ! in_array($tallaId, $elegibles, true)) {
                    $validator->errors()->add("activos.{$i}.talla_id", 'Esa variante no corresponde al activo o está desactivada.');

                    continue;
                } elseif ($elegibles === [] && $tallaId !== null) {
                    $validator->errors()->add("activos.{$i}.talla_id", 'Este activo no utiliza variantes.');

                    continue;
                }

                $clave = $activo->id.'-'.($tallaId ?? '0');
                $saldo = $saldosPorClave->get($clave);
                $disponible = $saldo !== null ? (int) $saldo->cantidad : 0;
                $solicitado = $solicitadoPorClave[$clave] ?? 0;

                if ($solicitado > $disponible) {
                    $validator->errors()->add(
                        "activos.{$i}.cantidad",
                        "No hay existencias suficientes de {$activo->nombre} en este almacén. Disponible: {$disponible}.",
                    );
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

                // Disponibilidad real del conjunto en ESE almacén (mínimo por
                // componente — mismo cálculo que `Conjunto::disponibilidad()`,
                // fuente única también usada por `CrearEntregaUniforme`).
                $cantidadSolicitada = (int) ($fila['cantidad'] ?? 0);
                $disponibleConjunto = $conjunto->disponibilidad($almacenId);

                if ($cantidadSolicitada > $disponibleConjunto) {
                    $validator->errors()->add(
                        "conjuntos.{$i}.cantidad",
                        "No hay suficiente disponibilidad del conjunto «{$conjunto->nombre}» en este almacén. Disponible: {$disponibleConjunto}.",
                    );
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
            'firma.required' => 'Solicita la firma del colaborador para continuar.',
            'firma_operador.required' => 'Falta la firma del encargado que realiza la entrega.',
            'aceptacion.accepted' => 'Debes confirmar la aceptación antes de finalizar la entrega.',
            'colaborador_id.exists' => 'El colaborador seleccionado no es válido o no tienes acceso a su empresa.',
            'activos.*.activo_id.required' => 'Selecciona un activo.',
            'activos.*.cantidad.required' => 'Indica la cantidad.',
            'activos.*.cantidad.min' => 'La cantidad debe ser mayor a cero.',
            'unidades.*.unidad_activo_id.required' => 'Selecciona una unidad identificada.',
            'unidades.*.unidad_activo_id.distinct' => 'No puedes elegir la misma unidad dos veces.',
            'unidades.*.unidad_activo_id.exists' => 'Esa unidad ya no está disponible en el almacén de origen (fue asignada, se movió o dejó de ser entregable).',
            'conjuntos.*.conjunto_id.required' => 'Selecciona un conjunto.',
            'conjuntos.*.cantidad.required' => 'Indica la cantidad.',
            'conjuntos.*.cantidad.min' => 'La cantidad debe ser mayor a cero.',
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
