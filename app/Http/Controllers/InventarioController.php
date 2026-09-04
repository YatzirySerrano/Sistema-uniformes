<?php

namespace App\Http\Controllers;

use App\Acciones\AjustarInventario;
use App\Acciones\RegistrarEntradaInventario;
use App\Enums\TipoControlActivo;
use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Requests\Activos\RegistrarEntradaInventarioRequest;
use App\Models\CategoriaActivo;
use App\Models\SaldoInventario;
use App\Models\TipoActivo;
use App\Servicios\ServicioInventario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inventario por EMPRESA + ALMACÉN. Un mismo almacén puede abastecer a varias
 * empresas y su stock se mantiene separado por empresa. La empresa y el almacén
 * llegan como filtro / campo y siempre se valida el acceso del usuario.
 */
class InventarioController extends Controller
{
    use ConEmpresa;

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('inventario.ver'), 403);

        $usuario = $request->user();
        $idsAutorizadas = $this->idsEmpresasAutorizadas($request);
        $empresaFiltro = $this->empresaDelFiltro($request);
        $idsScope = $empresaFiltro !== null ? collect([$empresaFiltro->id]) : $idsAutorizadas;

        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'almacen_id' => ['nullable', 'integer'],
            'activo_id' => ['nullable', 'integer'],
            'tipo_activo_id' => ['nullable', 'integer'],
            'categoria_id' => ['nullable', 'integer'],
            'talla_id' => ['nullable', 'integer'],
            'control' => ['nullable', Rule::in(['cantidad', 'individual'])],
            'estado_stock' => ['nullable', Rule::in(['bajo_minimo', 'sin_stock', 'con_stock'])],
        ]);

        $almacenesVisibles = $idsScope
            ->flatMap(fn (int $id): array => $this->acceso()->almacenesAutorizados($usuario, $id)->pluck('id')->all())
            ->unique()->values();

        $saldos = SaldoInventario::query()
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
            ->with(['empresa:id,nombre_comercial', 'almacen:id,nombre', 'activo:id,nombre,tipo_control', 'talla:id,valor'])
            ->orderBy('empresa_id')
            ->orderBy('almacen_id')
            ->paginate($this->porPagina())
            ->withQueryString()
            ->through(fn (SaldoInventario $s): array => [
                'id' => $s->id,
                'empresa_id' => $s->empresa_id,
                'empresa' => $s->empresa?->nombre_comercial,
                'almacen_id' => $s->almacen_id,
                'activo_id' => $s->activo_id,
                'talla_id' => $s->talla_id,
                'almacen' => $s->almacen?->nombre,
                'activo' => $s->activo?->nombre,
                'talla' => $s->talla?->valor,
                'control' => $s->activo?->tipo_control->value,
                'cantidad' => $s->cantidad,
                'minimo' => $s->minimo,
                'bajo_minimo' => $s->estaBajoMinimo(),
            ]);

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

    public function minimos(Request $request, ServicioInventario $inventario): RedirectResponse
    {
        abort_unless($request->user()->can('inventario.minimos'), 403);
        $empresa = $this->resolverEmpresa($request);

        $datos = $this->validarOperacion($request, $empresa->id, [
            'minimo' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);

        $inventario->ajustarMinimo(
            $empresa->id,
            (int) $datos['almacen_id'],
            (int) $datos['activo_id'],
            isset($datos['talla_id']) ? (int) $datos['talla_id'] : null,
            (int) $datos['minimo'],
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Mínimo actualizado.']);
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
