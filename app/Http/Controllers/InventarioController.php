<?php

namespace App\Http\Controllers;

use App\Acciones\AjustarInventario;
use App\Acciones\AjustarMinimoInventario;
use App\Acciones\MarcarCondicionInventario;
use App\Acciones\RegistrarEntradaInventario;
use App\Acciones\RestaurarCondicionInventario;
use App\Enums\CondicionDevolucion;
use App\Enums\TipoControlActivo;
use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Controllers\Concerns\ExportaListado;
use App\Http\Requests\Activos\RegistrarEntradaInventarioRequest;
use App\Models\Almacen;
use App\Models\CategoriaActivo;
use App\Models\SaldoInventario;
use App\Models\TipoActivo;
use App\Servicios\ServicioInventario;
use App\Soporte\ContextoExportacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Inventario por EMPRESA + ALMACÉN. Un mismo almacén puede abastecer a varias
 * empresas y su stock se mantiene separado por empresa. La empresa y el almacén
 * llegan como filtro / campo y siempre se valida el acceso del usuario.
 */
class InventarioController extends Controller
{
    use ConEmpresa;
    use ExportaListado;

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('inventario.ver'), 403);

        $filtros = $this->filtrosListado($request);
        $empresaFiltro = $this->empresaDelFiltro($request);

        $saldos = $this->consultaSaldos($request, $filtros)
            ->orderBy('empresa_id')
            ->orderBy('almacen_id')
            ->paginate($this->porPagina())
            ->withQueryString()
            ->through(fn (SaldoInventario $s): array => $this->filaSaldo($s));

        $usuario = $request->user();
        $idsScope = $this->idsScopeInventario($request);

        return Inertia::render('Inventario/Index', [
            'saldos' => $saldos,
            'filtros' => [...$filtros, 'empresa_id' => $empresaFiltro?->id],
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
            'almacenes' => $idsScope
                ->flatMap(fn (int $id): array => $this->acceso()->almacenesAutorizados($usuario, $id)->all())
                ->unique('id')
                ->map(fn ($a): array => ['id' => $a->id, 'nombre' => $a->nombre, 'codigo' => $a->codigo])
                ->values(),
            'tiposActivo' => TipoActivo::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'categorias' => CategoriaActivo::query()->where('activa', true)->orderBy('nombre')->get(['id', 'nombre']),
            'tiposControl' => TipoControlActivo::opciones(),
            'permisos' => [
                'entrada' => $request->user()->can('inventario.entrada'),
                'ajustar' => $request->user()->can('inventario.ajustar'),
                'minimos' => $request->user()->can('inventario.minimos'),
            ],
        ]);
    }

    /**
     * Excel/PDF de "Existencias globales", respetando EXACTAMENTE los mismos
     * filtros/alcance que `index()` (misma consulta base, nunca una aparte)
     * — mismo patrón que el resto de listados administrativos
     * (`ExportaListado`/`ListadoExport`). Exporta TODAS las filas filtradas,
     * no sólo la página visible en pantalla.
     */
    public function exportar(Request $request): BinaryFileResponse|HttpResponse
    {
        abort_unless($request->user()->can('inventario.ver'), 403);

        $filtros = $this->filtrosListado($request);
        $empresaFiltro = $this->empresaDelFiltro($request);

        $saldos = $this->consultaSaldos($request, $filtros)
            ->orderBy('empresa_id')
            ->orderBy('almacen_id')
            ->get();

        $filas = $saldos->map(function (SaldoInventario $s): array {
            $fila = $this->filaSaldo($s);

            return [
                $fila['empresa'],
                $fila['almacen'],
                $fila['activo'],
                $s->activo?->codigo,
                $s->activo?->tipoActivo?->nombre,
                $s->activo?->categoriaActivo?->nombre,
                $fila['talla'] ?: 'Sin variante',
                $s->activo?->tipo_control->etiqueta(),
                $fila['cantidad'],
                $fila['minimo'],
                match (true) {
                    $fila['cantidad'] <= 0 => 'Sin existencias',
                    $fila['bajo_minimo'] => 'Bajo mínimo',
                    default => 'OK',
                },
            ];
        })->all();

        $filtrosHumanos = array_filter([
            'Búsqueda' => $filtros['buscar'] ?? null,
            'Almacén' => ($filtros['almacen_id'] ?? null)
                ? Almacen::query()->whereKey($filtros['almacen_id'])->value('nombre')
                : null,
            'Tipo' => ($filtros['tipo_activo_id'] ?? null)
                ? TipoActivo::query()->whereKey($filtros['tipo_activo_id'])->value('nombre')
                : null,
            'Categoría' => ($filtros['categoria_id'] ?? null)
                ? CategoriaActivo::query()->whereKey($filtros['categoria_id'])->value('nombre')
                : null,
            'Control' => match ($filtros['control'] ?? null) {
                'cantidad' => 'Por cantidad',
                'individual' => 'Seguimiento individual',
                default => null,
            },
            'Estado' => match ($filtros['estado_stock'] ?? null) {
                'bajo_minimo' => 'Bajo mínimo',
                'sin_stock' => 'Sin stock',
                'con_stock' => 'Con stock',
                default => null,
            },
        ]);

        $contexto = new ContextoExportacion('Existencias globales', $empresaFiltro, $filtrosHumanos, $saldos->count(), generadoPor: $request->user()?->name);

        return $this->respuestaExportacion($request->input('formato', 'xlsx'), $filas, [
            'Empresa', 'Almacén', 'Activo', 'Código', 'Tipo', 'Categoría', 'Variante', 'Control', 'Existencia', 'Mínimo', 'Estado',
        ], $contexto);
    }

    /**
     * @return array<string, mixed>
     */
    private function filtrosListado(Request $request): array
    {
        return $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'almacen_id' => ['nullable', 'integer'],
            'activo_id' => ['nullable', 'integer'],
            'tipo_activo_id' => ['nullable', 'integer'],
            'categoria_id' => ['nullable', 'integer'],
            'talla_id' => ['nullable', 'integer'],
            'control' => ['nullable', Rule::in(['cantidad', 'individual'])],
            'estado_stock' => ['nullable', Rule::in(['bajo_minimo', 'sin_stock', 'con_stock'])],
        ]);
    }

    /**
     * @return Collection<int, int>
     */
    private function idsScopeInventario(Request $request): Collection
    {
        $idsAutorizadas = $this->idsEmpresasAutorizadas($request);
        $empresaFiltro = $this->empresaDelFiltro($request);

        return $empresaFiltro !== null ? collect([$empresaFiltro->id]) : $idsAutorizadas;
    }

    /**
     * Consulta filtrada compartida por `index()` (pagina + `through()`) y
     * `exportar()` (`get()` + `map()`) — misma consulta base, nunca una
     * aparte, para que pantalla y exportación nunca diverjan.
     *
     * @param  array<string, mixed>  $filtros
     * @return Builder<SaldoInventario>
     */
    private function consultaSaldos(Request $request, array $filtros): Builder
    {
        $usuario = $request->user();
        $idsScope = $this->idsScopeInventario($request);

        $almacenesVisibles = $idsScope
            ->flatMap(fn (int $id): array => $this->acceso()->almacenesAutorizados($usuario, $id)->pluck('id')->all())
            ->unique()->values();

        return SaldoInventario::query()
            ->whereIn('empresa_id', $idsScope)
            ->whereIn('almacen_id', $almacenesVisibles)
            ->when($filtros['buscar'] ?? null, function (Builder $q, string $texto): void {
                $q->where(function (Builder $sub) use ($texto): void {
                    $sub->whereHas('activo', function (Builder $a) use ($texto): void {
                        $a->where('nombre', 'like', "%{$texto}%")
                            ->orWhere('codigo', 'like', "%{$texto}%")
                            ->orWhere('categoria', 'like', "%{$texto}%")
                            ->orWhereHas('tipoActivo', fn (Builder $t) => $t->where('nombre', 'like', "%{$texto}%"))
                            ->orWhereHas('categoriaActivo', fn (Builder $c) => $c->where('nombre', 'like', "%{$texto}%"));
                    })->orWhereHas('talla', fn (Builder $t) => $t->where('valor', 'like', "%{$texto}%"))
                        ->orWhereHas('almacen', fn (Builder $al) => $al->where('nombre', 'like', "%{$texto}%")->orWhere('codigo', 'like', "%{$texto}%"));
                });
            })
            ->when($filtros['almacen_id'] ?? null, fn (Builder $q, $v) => $q->where('almacen_id', $v))
            ->when($filtros['activo_id'] ?? null, fn (Builder $q, $v) => $q->where('activo_id', $v))
            ->when($filtros['talla_id'] ?? null, fn (Builder $q, $v) => $q->where('talla_id', $v))
            ->when($filtros['tipo_activo_id'] ?? null, fn (Builder $q, $v) => $q->whereHas('activo', fn (Builder $a) => $a->where('tipo_activo_id', $v)))
            ->when($filtros['categoria_id'] ?? null, fn (Builder $q, $v) => $q->whereHas('activo', fn (Builder $a) => $a->where('categoria_id', $v)))
            ->when($filtros['control'] ?? null, fn (Builder $q, $v) => $q->whereHas('activo', fn (Builder $a) => $a->where('tipo_control', $v)))
            ->when(($filtros['estado_stock'] ?? null) === 'bajo_minimo', fn (Builder $q) => $q->bajoMinimo())
            ->when(($filtros['estado_stock'] ?? null) === 'sin_stock', fn (Builder $q) => $q->where('cantidad', '<=', 0))
            ->when(($filtros['estado_stock'] ?? null) === 'con_stock', fn (Builder $q) => $q->where('cantidad', '>', 0))
            ->with(['empresa:id,nombre_comercial', 'almacen:id,nombre', 'activo:id,nombre,codigo,tipo_control', 'activo.tipoActivo:id,nombre', 'activo.categoriaActivo:id,nombre', 'talla:id,valor']);
    }

    /**
     * @return array<string, mixed>
     */
    private function filaSaldo(SaldoInventario $s): array
    {
        return [
            'id' => $s->id,
            'empresa_id' => $s->empresa_id,
            'empresa' => $s->empresa?->nombre_comercial,
            'almacen_id' => $s->almacen_id,
            'activo_id' => $s->activo_id,
            'talla_id' => $s->talla_id,
            'almacen' => $s->almacen?->nombre,
            'activo' => $s->activo?->nombre,
            'activo_codigo' => $s->activo?->codigo,
            'talla' => $s->talla?->valor,
            'control' => $s->activo?->tipo_control->value,
            'cantidad' => $s->cantidad,
            'minimo' => $s->minimo,
            'bajo_minimo' => $s->estaBajoMinimo(),
        ];
    }

    public function formularioEntrada(Request $request): Response
    {
        abort_unless($request->user()->can('inventario.entrada'), 403);

        return Inertia::render('Inventario/Entrada', [
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
        ]);
    }

    public function entrada(RegistrarEntradaInventarioRequest $request, RegistrarEntradaInventario $accion): RedirectResponse
    {
        $empresa = $request->empresaResuelta();
        $datos = $request->validated();

        $accion->ejecutar(
            $empresa->id,
            (int) $datos['almacen_id'],
            $datos['items'],
            $datos['motivo'],
            $request->user()->id,
            $request->boolean('carga_inicial'),
            $datos['notas'] ?? null,
        );

        return to_route('inventario.index')->with('toast', ['type' => 'success', 'message' => 'Entrada de inventario registrada.']);
    }

    public function ajuste(Request $request, AjustarInventario $accion): RedirectResponse
    {
        abort_unless($request->user()->can('inventario.ajustar'), 403);
        $empresa = $this->resolverEmpresa($request);

        $datos = $this->validarOperacion($request, $empresa->id, [
            'existencia_objetivo' => ['required', 'integer', 'min:0', 'max:1000000'],
            'motivo' => ['required', 'string', 'max:255'],
        ]);

        $accion->ejecutar(
            $empresa->id,
            (int) $datos['almacen_id'],
            (int) $datos['activo_id'],
            isset($datos['talla_id']) ? (int) $datos['talla_id'] : null,
            (int) $datos['existencia_objetivo'],
            $datos['motivo'],
            $request->user()->id,
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Ajuste de existencias registrado.']);
    }

    /**
     * Marca N piezas de un activo por CANTIDAD como Dañado o Baja,
     * directamente desde el stock disponible (nunca desde una devolución).
     * Mismo permiso que "Ajustar existencias": conceptualmente es también
     * una corrección del inventario físico frente al conteo real.
     */
    public function marcarCondicion(Request $request, MarcarCondicionInventario $accion): RedirectResponse
    {
        abort_unless($request->user()->can('inventario.ajustar'), 403);
        $empresa = $this->resolverEmpresa($request);

        $datos = $this->validarOperacion($request, $empresa->id, [
            'condicion' => ['required', Rule::in([CondicionDevolucion::Danado->value, CondicionDevolucion::Baja->value])],
            'cantidad' => ['required', 'integer', 'min:1', 'max:1000000'],
            'motivo' => ['required', 'string', 'max:255'],
        ]);

        $accion->ejecutar(
            $empresa->id,
            (int) $datos['almacen_id'],
            (int) $datos['activo_id'],
            isset($datos['talla_id']) ? (int) $datos['talla_id'] : null,
            CondicionDevolucion::from($datos['condicion']),
            (int) $datos['cantidad'],
            $datos['motivo'],
            $request->user()->id,
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Condición de inventario registrada.']);
    }

    /**
     * Restaura N piezas marcadas como Dañado de vuelta a Disponible (se
     * repararon o el conteo estaba mal). Nunca aplica a Baja (terminal).
     */
    public function restaurarCondicion(Request $request, RestaurarCondicionInventario $accion): RedirectResponse
    {
        abort_unless($request->user()->can('inventario.ajustar'), 403);
        $empresa = $this->resolverEmpresa($request);

        $datos = $this->validarOperacion($request, $empresa->id, [
            'cantidad' => ['required', 'integer', 'min:1', 'max:1000000'],
            'motivo' => ['required', 'string', 'max:255'],
        ]);

        $accion->ejecutar(
            $empresa->id,
            (int) $datos['almacen_id'],
            (int) $datos['activo_id'],
            isset($datos['talla_id']) ? (int) $datos['talla_id'] : null,
            (int) $datos['cantidad'],
            $datos['motivo'],
            $request->user()->id,
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Existencia restaurada a disponible.']);
    }

    public function minimos(Request $request, AjustarMinimoInventario $ajustarMinimo): RedirectResponse
    {
        abort_unless($request->user()->can('inventario.minimos'), 403);
        $empresa = $this->resolverEmpresa($request);

        $datos = $this->validarOperacion($request, $empresa->id, [
            'minimo' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);

        $ajustarMinimo->ejecutar(
            $empresa->id,
            (int) $datos['almacen_id'],
            (int) $datos['activo_id'],
            isset($datos['talla_id']) ? (int) $datos['talla_id'] : null,
            (int) $datos['minimo'],
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Mínimo actualizado.']);
    }

    /**
     * Vista previa de "aplicar mínimo masivo": cuántas combinaciones de saldo
     * YA EXISTENTES caen dentro del alcance recibido, antes de que el usuario
     * confirme. `activo_id` acota a un solo activo (todas sus variantes en un
     * almacén, desde el Detalle del activo); sin él, el alcance es TODA la
     * empresa + almacén (desde el listado general de Inventario).
     */
    public function previsualizarMinimoMasivo(Request $request, ServicioInventario $inventario): JsonResponse
    {
        abort_unless($request->user()->can('inventario.minimos'), 403);
        $empresa = $this->resolverEmpresa($request);
        $datos = $this->validarAlcanceMinimoMasivo($request, $empresa->id);

        return response()->json([
            'combinaciones' => $inventario->contarCombinacionesConSaldo(
                $empresa->id,
                (int) $datos['almacen_id'],
                isset($datos['activo_id']) ? (int) $datos['activo_id'] : null,
            ),
        ]);
    }

    /**
     * Aplica el mismo mínimo a todas las combinaciones de saldo del alcance
     * (empresa + almacén, y opcionalmente un solo activo) — NUNCA cruza a
     * otra empresa o almacén distintos de los recibidos y validados.
     */
    public function aplicarMinimoMasivo(Request $request, ServicioInventario $inventario): RedirectResponse
    {
        abort_unless($request->user()->can('inventario.minimos'), 403);
        $empresa = $this->resolverEmpresa($request);
        $datos = $this->validarAlcanceMinimoMasivo($request, $empresa->id, [
            'minimo' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);

        $afectadas = $inventario->aplicarMinimoMasivo(
            $empresa->id,
            (int) $datos['almacen_id'],
            (int) $datos['minimo'],
            isset($datos['activo_id']) ? (int) $datos['activo_id'] : null,
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => "Mínimo aplicado a {$afectadas} combinación(es).",
        ]);
    }

    /**
     * Reglas del alcance de una aplicación masiva de mínimo: el almacén debe
     * abastecer a la empresa y estar activo; si viene `activo_id` (acotar a
     * un solo activo, ej. "todas sus variantes"), debe pertenecer a la
     * empresa. No exige `activo_id` — sin él el alcance es toda la empresa +
     * almacén.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function validarAlcanceMinimoMasivo(Request $request, int $empresaId, array $extra = []): array
    {
        return $request->validate([
            'almacen_id' => [
                'required', 'integer',
                Rule::exists('almacen_empresa', 'almacen_id')->where(fn ($q) => $q->where('empresa_id', $empresaId)),
                Rule::exists('almacenes', 'id')->where(fn ($q) => $q->where('activo', true)),
            ],
            'activo_id' => [
                'nullable', 'integer',
                Rule::exists('activos', 'id')->where(fn ($q) => $q->where('empresa_id', $empresaId)),
            ],
            ...$extra,
        ], [
            'almacen_id.exists' => 'El almacén no abastece a esta empresa o está desactivado.',
            'activo_id.exists' => 'El activo no pertenece a esta empresa.',
        ]);
    }

    /**
     * Reglas comunes de un ajuste / mínimo (corrección de una fila de saldo que
     * YA existe): el almacén debe abastecer a la empresa y estar activo; el
     * activo debe pertenecer a la empresa; la variante debe ser nula ("sin
     * variante") o estar asociada al activo.
     *
     * NOTA: a diferencia de "Registrar entrada", aquí NO se exige que la variante
     * siga habilitada para la empresa: hay que poder corregir o poner a cero
     * existencias históricas de variantes que después se deshabilitaron.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function validarOperacion(Request $request, int $empresaId, array $extra): array
    {
        return $request->validate([
            'empresa_id' => ['required', 'integer'],
            'almacen_id' => [
                'required', 'integer',
                Rule::exists('almacen_empresa', 'almacen_id')->where(fn ($q) => $q->where('empresa_id', $empresaId)),
                Rule::exists('almacenes', 'id')->where(fn ($q) => $q->where('activo', true)),
            ],
            'activo_id' => [
                'required', 'integer',
                Rule::exists('activos', 'id')->where(fn ($q) => $q->where('empresa_id', $empresaId)),
            ],
            'talla_id' => [
                'nullable', 'integer',
                Rule::exists('activo_talla', 'talla_id')->where(fn ($q) => $q->where('activo_id', (int) $request->input('activo_id'))),
            ],
            ...$extra,
        ], [
            'almacen_id.exists' => 'El almacén no abastece a esta empresa o está desactivado.',
            'activo_id.exists' => 'El activo no pertenece a esta empresa.',
            'talla_id.exists' => 'Esa variante no corresponde al activo seleccionado.',
        ]);
    }
}
