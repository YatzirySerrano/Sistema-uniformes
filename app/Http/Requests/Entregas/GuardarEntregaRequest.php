<?php

namespace App\Http\Requests\Entregas;

use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoUnidadActivo;
use App\Enums\FinalidadCustodia;
use App\Enums\TipoControlActivo;
use App\Models\Activo;
use App\Models\CambioServicioColaborador;
use App\Models\Colaborador;
use App\Models\Conjunto;
use App\Models\EntregaUniforme;
use App\Models\SaldoInventario;
use App\Models\Talla;
use App\Models\UnidadActivo;
use App\Servicios\ServicioCustodiaColaborador;
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
 *
 * ORIGEN de los bienes (`origen`):
 * - `almacen` (por defecto): salida de almacén — exige `entregarDesdeAlmacen`
 *   y valida contra el saldo del almacén elegido.
 * - `custodia`: redistribución — exige `redistribuir` y valida EXCLUSIVAMENTE
 *   contra la custodia actual del colaborador ligado al usuario autenticado
 *   (`ServicioCustodiaColaborador`). Nada de lo que mande el frontend
 *   (ids, cantidades, unidades) se acepta si no está hoy en esa custodia;
 *   `RedistribuirCustodia` lo revalida además bajo lock.
 */
class GuardarEntregaRequest extends FormRequest
{
    public const ORIGEN_ALMACEN = 'almacen';

    public const ORIGEN_CUSTODIA = 'custodia';

    private ?Colaborador $custodioResuelto = null;

    private bool $custodioYaResuelto = false;

    public function authorize(): bool
    {
        $usuario = $this->user();

        if ($usuario === null) {
            return false;
        }

        return $this->esRedistribucion()
            ? $usuario->can('redistribuir', EntregaUniforme::class)
            : $usuario->can('entregarDesdeAlmacen', EntregaUniforme::class);
    }

    public function esRedistribucion(): bool
    {
        return $this->input('origen') === self::ORIGEN_CUSTODIA;
    }

    /**
     * Revisión de cambio de servicio en cuyo nombre se redistribuye (la
     * custodia es del colaborador revisado, no del usuario), si viene.
     */
    public function cambioServicio(): ?CambioServicioColaborador
    {
        return $this->filled('cambio_servicio_id')
            ? CambioServicioColaborador::query()->with('colaborador.sucursal')->find($this->integer('cambio_servicio_id'))
            : null;
    }

    /**
     * Colaborador cuya custodia se redistribuye. Lo resuelve SIEMPRE el
     * backend, nunca un id del formulario:
     * - normal: la ficha vinculada a la cuenta autenticada ("mi custodia");
     * - dentro de una revisión de cambio de servicio: el colaborador revisado,
     *   sólo si el usuario puede redistribuir su custodia
     *   (`CambioServicioColaboradorPolicy::redistribuirCustodia`).
     * `null` si no aplica o si no hay custodia válida en la empresa del
     * destinatario.
     */
    public function custodio(): ?Colaborador
    {
        if ($this->custodioYaResuelto) {
            return $this->custodioResuelto;
        }

        $this->custodioYaResuelto = true;
        $destinatario = Colaborador::query()->find($this->integer('colaborador_id'));
        $usuario = $this->user();

        if (! $this->esRedistribucion() || $destinatario === null || $usuario === null) {
            return null;
        }

        if ($this->filled('cambio_servicio_id')) {
            $cambio = $this->cambioServicio();

            return $this->custodioResuelto = $cambio !== null
                && $usuario->can('redistribuirCustodia', $cambio)
                && $cambio->colaborador->empresa_id === $destinatario->empresa_id
                    ? $cambio->colaborador
                    : null;
        }

        return $this->custodioResuelto = app(ServicioCustodiaColaborador::class)->custodioDeUsuario($usuario, $destinatario->empresa_id);
    }

    /**
     * ¿Puede tomar de la bolsa de uso personal / sin clasificar? Con
     * `entregas.redistribuir-propios`, o dentro de la revisión de un cambio
     * de servicio (esa revisión ya decidió bien por bien qué se redistribuye).
     */
    public function incluirPersonales(): bool
    {
        if (! $this->esRedistribucion()) {
            return false;
        }

        return $this->filled('cambio_servicio_id') || ($this->user()?->can('redistribuirPropios', EntregaUniforme::class) ?? false);
    }

    /**
     * Finalidad de cada renglón para quien RECIBE (uso personal / para
     * redistribuir). Nullable por compatibilidad: sin ella el renglón queda
     * "sin clasificar" (el formulario siempre la envía).
     *
     * @return array<string, mixed>
     */
    private function reglasFinalidad(): array
    {
        $finalidad = ['nullable', Rule::enum(FinalidadCustodia::class)];

        return [
            'activos.*.finalidad' => $finalidad,
            'unidades.*.finalidad' => $finalidad,
            'conjuntos.*.finalidad' => $finalidad,
            'conjuntos.*.finalidades' => ['nullable', 'array'],
            'conjuntos.*.finalidades.*' => $finalidad,
        ];
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

        if ($this->esRedistribucion()) {
            return [...$this->reglasComunes($empresaId), ...$this->reglasCustodia($empresaId), ...$this->reglasFinalidad()];
        }

        return [
            ...$this->reglasComunes($empresaId),
            ...$this->reglasFinalidad(),
            'almacen_id' => [
                'required', 'integer',
                Rule::exists('almacen_empresa', 'almacen_id')->where(fn ($q) => $q->where('empresa_id', $empresaId)),
            ],
            // Token del apartado temporal armado en el paso 2 (ver
            // `App\Acciones\ReservarInventarioEntrega`). Opcional por
            // compatibilidad; si viene, `CrearEntregaUniforme` la valida y
            // consume — nunca reemplaza sus propias revalidaciones.
            'reserva_token' => ['nullable', 'uuid'],

            'activos' => ['nullable', 'array'],
            'activos.*.activo_id' => [
                'required', 'integer',
                Rule::exists('activos', 'id')->where(fn ($q) => $q->where('empresa_id', $empresaId)->where('tipo_control', 'cantidad')->where('activo', true)),
            ],
            'activos.*.talla_id' => ['nullable', 'integer', Rule::exists('tallas', 'id')->where(fn ($q) => $q->where('activa', true))],
            'activos.*.cantidad' => ['required', 'integer', 'min:1', 'max:1000'],
            // Evidencia fotográfica OPCIONAL por renglón (foto de cámara o
            // archivo). Se guarda en disco privado ligada al DetalleEntrega.
            'activos.*.evidencia' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'activos.*.evidencia_origen' => ['nullable', 'in:camara,archivo'],

            'unidades' => ['nullable', 'array'],
            'unidades.*.unidad_activo_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('unidades_activo', 'id')->where(fn ($q) => $q
                    ->where('empresa_id', $empresaId)
                    ->where('almacen_id', $this->integer('almacen_id'))
                    ->where('estado', EstadoUnidadActivo::EnAlmacen->value)
                    ->where('condicion', CondicionUnidadActivo::Funcionando->value)),
            ],
            'unidades.*.evidencia' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'unidades.*.evidencia_origen' => ['nullable', 'in:camara,archivo'],

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
     * Reglas compartidas por ambos orígenes.
     *
     * @return array<string, mixed>
     */
    private function reglasComunes(int $empresaId): array
    {
        return [
            'origen' => ['nullable', Rule::in([self::ORIGEN_ALMACEN, self::ORIGEN_CUSTODIA])],
            'colaborador_id' => [
                'required', 'integer',
                Rule::exists('colaboradores', 'id')->where(fn ($q) => $q->where('empresa_id', $empresaId)->where('activo', true)),
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
        ];
    }

    /**
     * Redistribución: sin almacén ni conjuntos (el conjunto es una plantilla
     * de salida de almacén); cada activo/unidad debe estar HOY en la
     * custodia del colaborador ligado al usuario.
     *
     * @return array<string, mixed>
     */
    private function reglasCustodia(int $empresaId): array
    {
        $custodioId = $this->custodio()?->getKey() ?? 0;

        return [
            'cambio_servicio_id' => ['nullable', 'integer'],

            // Conjuntos que el custodio recibió como tal: sólo contexto, se
            // expanden a los componentes REALES de su custodia.
            'conjuntos' => ['nullable', 'array'],
            'conjuntos.*.conjunto_id' => [
                'required', 'integer',
                Rule::exists('conjuntos', 'id')->where(fn ($q) => $q->where('empresa_id', $empresaId)),
            ],
            'conjuntos.*.cantidad' => ['required', 'integer', 'min:1', 'max:100'],

            'activos' => ['nullable', 'array'],
            'activos.*.activo_id' => [
                'required', 'integer',
                Rule::exists('activos', 'id')->where(fn ($q) => $q->where('empresa_id', $empresaId)->where('tipo_control', 'cantidad')->where('activo', true)),
            ],
            // La variante es la que TIENE la pieza en custodia (aunque hoy
            // esté retirada del catálogo): sólo se exige que exista.
            'activos.*.talla_id' => ['nullable', 'integer', Rule::exists('tallas', 'id')],
            // De qué bolsa de la custodia sale: "para redistribuir" (por
            // defecto) o "uso personal / sin clasificar" (requiere permiso).
            'activos.*.bolsa' => ['nullable', Rule::in([ServicioCustodiaColaborador::BOLSA_REDISTRIBUCION, ServicioCustodiaColaborador::BOLSA_PERSONAL])],
            'activos.*.cantidad' => ['required', 'integer', 'min:1', 'max:1000'],
            'activos.*.evidencia' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'activos.*.evidencia_origen' => ['nullable', 'in:camara,archivo'],

            'unidades' => ['nullable', 'array'],
            'unidades.*.unidad_activo_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('unidades_activo', 'id')->where(fn ($q) => $q
                    ->where('empresa_id', $empresaId)
                    ->where('colaborador_id', $custodioId)
                    ->where('estado', EstadoUnidadActivo::Asignada->value)
                    ->where('condicion', CondicionUnidadActivo::Funcionando->value)),
            ],
            'unidades.*.evidencia' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'unidades.*.evidencia_origen' => ['nullable', 'in:camara,archivo'],
        ];
    }

    /**
     * Cantidades solicitadas vs. custodia ACTUAL del custodio (misma fuente
     * que el selector y que la acción). Aviso temprano con error por renglón;
     * la autoridad final es `RedistribuirCustodia` bajo lock.
     *
     * @param  array<int|string, mixed>  $activos
     * @param  array<int|string, mixed>  $conjuntos
     */
    private function validarContraCustodia(Validator $validator, Colaborador $destinatario, array $activos, array $conjuntos = []): void
    {
        $custodio = $this->custodio();

        if ($custodio === null) {
            $validator->errors()->add('origen', $this->filled('cambio_servicio_id')
                ? 'No puedes redistribuir la custodia de ese colaborador (la revisión ya terminó, es de otra empresa o no tienes permiso).'
                : 'Tu cuenta no está vinculada a una ficha de colaborador de esta empresa, así que no tienes activos bajo custodia que redistribuir.');

            return;
        }

        if ($custodio->getKey() === $destinatario->getKey()) {
            $validator->errors()->add('colaborador_id', 'No puedes entregarte a ti mismo activos de tu propia custodia.');

            return;
        }

        $servicio = app(ServicioCustodiaColaborador::class);
        $incluirPersonales = $this->incluirPersonales();
        $bolsaDe = fn (array $fila): string => ($fila['bolsa'] ?? null) === ServicioCustodiaColaborador::BOLSA_PERSONAL
            ? ServicioCustodiaColaborador::BOLSA_PERSONAL
            : ServicioCustodiaColaborador::BOLSA_REDISTRIBUCION;

        $disponibles = collect($servicio->cantidadesRedistribuibles($custodio, $incluirPersonales))
            ->keyBy(fn (array $f): string => $f['activo_id'].'-'.($f['talla_id'] ?? '0').'-'.$f['bolsa']);

        $solicitado = [];
        foreach ($activos as $fila) {
            if (! is_array($fila)) {
                continue;
            }
            $clave = (int) ($fila['activo_id'] ?? 0).'-'.((($fila['talla_id'] ?? null) !== null && $fila['talla_id'] !== '') ? (int) $fila['talla_id'] : '0').'-'.$bolsaDe($fila);
            $solicitado[$clave] = ($solicitado[$clave] ?? 0) + (int) ($fila['cantidad'] ?? 0);
        }

        foreach ($activos as $i => $fila) {
            if (! is_array($fila) || $validator->errors()->has("activos.{$i}.activo_id") || $validator->errors()->has("activos.{$i}.talla_id")) {
                continue;
            }

            if ($bolsaDe($fila) === ServicioCustodiaColaborador::BOLSA_PERSONAL && ! $incluirPersonales) {
                $validator->errors()->add("activos.{$i}.activo_id", 'No tienes permiso para reasignar activos de uso personal de tu custodia.');

                continue;
            }

            $clave = (int) ($fila['activo_id'] ?? 0).'-'.((($fila['talla_id'] ?? null) !== null && $fila['talla_id'] !== '') ? (int) $fila['talla_id'] : '0').'-'.$bolsaDe($fila);
            $enCustodia = $disponibles->get($clave);

            if ($enCustodia === null) {
                $validator->errors()->add("activos.{$i}.activo_id", $bolsaDe($fila) === ServicioCustodiaColaborador::BOLSA_REDISTRIBUCION
                    ? 'Ese activo (con esa variante) no está en tu custodia para redistribuir.'
                    : 'Ese activo (con esa variante) no está en tu custodia de uso personal.');

                continue;
            }

            if (($solicitado[$clave] ?? 0) > $enCustodia['disponible']) {
                $talla = $enCustodia['talla'] !== null ? ' talla '.$enCustodia['talla'] : '';
                $validator->errors()->add(
                    "activos.{$i}.cantidad",
                    "En tu custodia sólo quedan {$enCustodia['disponible']} de {$enCustodia['activo']}{$talla}.",
                );
            }
        }

        // Unidades de uso personal / sin clasificar: sólo con permiso.
        if (! $incluirPersonales) {
            foreach ((array) $this->input('unidades', []) as $i => $fila) {
                $unidad = is_array($fila) ? UnidadActivo::query()->find((int) ($fila['unidad_activo_id'] ?? 0)) : null;

                if ($unidad !== null
                    && $unidad->colaborador_id === $custodio->getKey()
                    && $servicio->bolsaDeUnidad($unidad) !== ServicioCustodiaColaborador::BOLSA_REDISTRIBUCION) {
                    $validator->errors()->add("unidades.{$i}.unidad_activo_id", "La unidad {$unidad->codigo} es de uso personal (o sin clasificar) y no tienes permiso para reasignarla.");
                }
            }
        }

        if ($conjuntos === []) {
            return;
        }

        $completos = collect(app(ServicioCustodiaColaborador::class)->conjuntosRedistribuibles($custodio))
            ->keyBy('conjunto_id');

        foreach ($conjuntos as $i => $fila) {
            if (! is_array($fila) || $validator->errors()->has("conjuntos.{$i}.conjunto_id")) {
                continue;
            }

            $enCustodia = $completos->get((int) ($fila['conjunto_id'] ?? 0));
            $disponibles = $enCustodia['completos'] ?? 0;

            if ((int) ($fila['cantidad'] ?? 0) > $disponibles) {
                $validator->errors()->add(
                    "conjuntos.{$i}.cantidad",
                    $disponibles === 0
                        ? 'Ese conjunto no está completo en tu custodia. Entrega sus piezas por separado.'
                        : "En tu custodia sólo hay {$disponibles} conjunto(s) completo(s).",
                );
            }
        }
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
                $validator->errors()->add('items', $this->esRedistribucion()
                    ? 'Agrega al menos un activo o unidad de tu custodia a la entrega.'
                    : 'Agrega al menos un activo, unidad identificada o conjunto a la entrega.');

                return;
            }

            $colaborador = Colaborador::query()->with('sucursal')->find($this->integer('colaborador_id'));
            if ($colaborador === null) {
                return;
            }

            // Sin acceso a la EMPRESA del destinatario no se valida nada más:
            // el controlador responde 403 (no se revela nada de esa empresa).
            $usuario = $this->user();
            if ($usuario === null || ! $usuario->puedeAccederEmpresa($colaborador->empresa_id)) {
                return;
            }

            // Alcance de SUCURSAL del destinatario: un usuario restringido a
            // ciertas sucursales no entrega a colaboradores de otras.
            if ($colaborador->sucursal !== null && ! $usuario->puedeAccederSucursal($colaborador->sucursal)) {
                $validator->errors()->add('colaborador_id', 'No tienes acceso a la sucursal de ese colaborador.');

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

            if ($this->esRedistribucion()) {
                $this->validarContraCustodia($validator, $colaborador, $activos, $conjuntos);

                return;
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
                    $tallaTxt = $tallaId !== null
                        ? ' talla '.(Talla::query()->whereKey($tallaId)->value('valor') ?? '')
                        : '';
                    $validator->errors()->add(
                        "activos.{$i}.cantidad",
                        "Solo hay {$disponible} unidades disponibles de {$activo->nombre}{$tallaTxt} en este almacén.",
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
                // Se pasa SIEMPRE el mapa de variantes elegidas (aunque venga
                // vacío): activa el modo "Entrega" en `disponibilidad()`, que
                // nunca suma stock de una variante distinta a la elegida para
                // un componente de talla libre.
                $cantidadSolicitada = (int) ($fila['cantidad'] ?? 0);
                $disponibleConjunto = $conjunto->disponibilidad($almacenId, $variantesElegidas);

                if ($cantidadSolicitada > $disponibleConjunto) {
                    $validator->errors()->add(
                        "conjuntos.{$i}.cantidad",
                        "No hay suficiente disponibilidad del conjunto «{$conjunto->nombre}» en este almacén. Disponible: {$disponibleConjunto}.",
                    );
                }
            }

            // Demanda COMBINADA por clave activo+talla: un artículo suelto y un
            // conjunto (o dos conjuntos) pueden consumir la MISMA existencia sin
            // que ninguno de los dos chequeos anteriores lo detecte por
            // separado (cada uno mira su propio origen contra el saldo bruto).
            // Aquí se suma TODA la demanda — sueltos + componentes de TODOS los
            // conjuntos, por cantidad_requerida × cantidad del renglón — y se
            // compara una sola vez contra el saldo real. `CrearEntregaUniforme`
            // conserva de todas formas su propio rechazo autoritativo bajo
            // lock; esto es para que el usuario no llegue hasta las firmas con
            // una selección ya imposible.
            $demandaCombinada = $solicitadoPorClave;
            foreach ($conjuntos as $fila) {
                $conjunto = $conjuntosCargados->get((int) ($fila['conjunto_id'] ?? 0));
                if ($conjunto === null) {
                    continue;
                }
                $cantidadConjuntos = (int) ($fila['cantidad'] ?? 0);
                if ($cantidadConjuntos <= 0) {
                    continue;
                }
                $variantesElegidas = is_array($fila['variantes'] ?? null) ? $fila['variantes'] : [];

                foreach ($conjunto->componentes as $componente) {
                    $activoComponente = Activo::query()->find($componente->activo_id);
                    if ($activoComponente === null || $activoComponente->tipo_control !== TipoControlActivo::Cantidad) {
                        continue;
                    }
                    $tallaId = Conjunto::resolverTallaComponente($componente, $variantesElegidas);
                    $clave = $componente->activo_id.'-'.($tallaId ?? '0');
                    $demandaCombinada[$clave] = ($demandaCombinada[$clave] ?? 0) + $componente->cantidad_requerida * $cantidadConjuntos;
                }
            }

            if ($demandaCombinada !== [] && ! $validator->errors()->has('items')) {
                $clavesConDemandaMixta = array_keys(array_filter(
                    $demandaCombinada,
                    fn (int $total, string $clave): bool => $total > 0 && $total !== ($solicitadoPorClave[$clave] ?? 0),
                    ARRAY_FILTER_USE_BOTH,
                ));

                if ($clavesConDemandaMixta !== []) {
                    $saldosCombinados = SaldoInventario::query()
                        ->where('empresa_id', $empresaId)
                        ->where('almacen_id', $almacenId)
                        ->get()
                        ->keyBy(fn (SaldoInventario $s): string => $s->activo_id.'-'.($s->talla_id ?? '0'));

                    foreach ($clavesConDemandaMixta as $clave) {
                        $solicitado = $demandaCombinada[$clave];
                        $saldoCombinado = $saldosCombinados->get($clave);
                        $disponible = $saldoCombinado === null ? 0 : (int) $saldoCombinado->cantidad;
                        if ($solicitado <= $disponible) {
                            continue;
                        }
                        [$activoId] = explode('-', $clave);
                        $nombreActivo = Activo::query()->whereKey((int) $activoId)->value('nombre') ?? 'un activo';
                        $validator->errors()->add(
                            'items',
                            "Solicitaste {$solicitado} piezas de {$nombreActivo} combinando artículos sueltos y conjuntos, pero sólo hay {$disponible} disponibles en este almacén.",
                        );
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
            'firma.required' => 'Solicita la firma del colaborador para continuar.',
            'firma_operador.required' => 'Falta la firma del encargado que realiza la entrega.',
            'aceptacion.accepted' => 'Debes confirmar la aceptación antes de finalizar la entrega.',
            'colaborador_id.exists' => 'El colaborador seleccionado no es válido o no tienes acceso a su empresa.',
            'activos.*.activo_id.required' => 'Selecciona un activo.',
            'activos.*.cantidad.required' => 'Indica la cantidad.',
            'activos.*.cantidad.min' => 'La cantidad debe ser mayor a cero.',
            'unidades.*.unidad_activo_id.required' => 'Selecciona una unidad identificada.',
            'unidades.*.unidad_activo_id.distinct' => 'No puedes elegir la misma unidad dos veces.',
            'unidades.*.unidad_activo_id.exists' => $this->esRedistribucion()
                ? 'Esa unidad ya no está bajo tu custodia (fue entregada, devuelta o reportada) o no puede redistribuirse.'
                : 'Esa unidad ya no está disponible en el almacén de origen (fue asignada, se movió o dejó de ser entregable).',
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
