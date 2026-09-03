<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Requests\Almacenes\GuardarAlmacenRequest;
use App\Models\Almacen;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\SaldoInventario;
use App\Servicios\ServicioAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Almacenes multiempresa. Un almacén abastece a una o varias empresas (N:M vía
 * `almacen_empresa`); el inventario se mantiene separado por empresa dentro del
 * almacén. La empresa nunca es la autoridad global: se recibe como filtro
 * (listado) o como conjunto `empresa_ids` (alta/edición) y siempre se valida el
 * acceso del usuario.
 */
class AlmacenController extends Controller
{
    use ConEmpresa;

    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Almacen::class);

        $usuario = $request->user();
        $idsAutorizadas = $this->idsEmpresasAutorizadas($request);
        $empresaFiltro = $this->empresaDelFiltro($request);

        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', 'in:activos,inactivos'],
            'orden' => ['nullable', 'in:az,za'],
        ]);

        $orden = ($filtros['orden'] ?? 'az') === 'za' ? 'desc' : 'asc';

        $almacenes = Almacen::query()
            ->whereHas('empresas', fn (Builder $q) => $q->whereIn('empresas.id', $idsAutorizadas))
            ->when($empresaFiltro !== null, fn (Builder $q) => $q->paraEmpresa($empresaFiltro->id))
            ->with(['responsable:id,nombre_completo,numero_empleado', 'empresas:id,codigo,nombre_comercial'])
            ->withCount('empresas')
            ->when($filtros['buscar'] ?? null, function (Builder $q, string $buscar): void {
                $q->where(function (Builder $sub) use ($buscar): void {
                    $sub->where('nombre', 'like', "%{$buscar}%")
                        ->orWhere('codigo', 'like', "%{$buscar}%")
                        ->orWhere('direccion', 'like', "%{$buscar}%");
                });
            })
            ->when(($filtros['estado'] ?? null) === 'activos', fn (Builder $q) => $q->where('activo', true))
            ->when(($filtros['estado'] ?? null) === 'inactivos', fn (Builder $q) => $q->where('activo', false))
            ->orderBy('nombre', $orden)
            ->paginate($this->porPagina())
            ->withQueryString()
            ->through(fn (Almacen $a): array => [
                'id' => $a->id,
                'nombre' => $a->nombre,
                'codigo' => $a->codigo,
                'direccion' => $a->direccion,
                'activo' => $a->activo,
                'empresas_count' => (int) $a->empresas_count,
                'empresas' => $a->empresas->map(fn (Empresa $e): array => [
                    'id' => $e->id, 'nombre_comercial' => $e->nombre_comercial,
                ])->all(),
                'responsable' => $a->responsable === null ? null : [
                    'id' => $a->responsable->id,
                    'nombre_completo' => $a->responsable->nombre_completo,
                    'numero_empleado' => $a->responsable->numero_empleado,
                ],
            ]);

        return Inertia::render('Almacenes/Index', [
            'almacenes' => $almacenes,
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
            'filtros' => [
                'buscar' => $filtros['buscar'] ?? '',
                'estado' => $filtros['estado'] ?? '',
                'orden' => $filtros['orden'] ?? 'az',
                'empresa_id' => $empresaFiltro?->id,
            ],
            'permisos' => [
                'crear' => $usuario->can('create', Almacen::class),
                'editar' => $usuario->can('almacenes.editar'),
                'administrar' => $usuario->can('almacenes.administrar'),
            ],
        ]);
    }

    public function show(Request $request, Almacen $almacen): Response
    {
        $this->authorize('view', $almacen);

        $almacen->load([
            'responsable:id,nombre_completo,numero_empleado,area_id',
            'responsable.departamento:id,nombre',
            'empresas:id,codigo,nombre_comercial',
        ]);

        $saldos = SaldoInventario::query()
            ->where('almacen_id', $almacen->id)
            ->whereIn('empresa_id', $almacen->empresas->pluck('id'))
            ->with([
                'empresa:id,nombre_comercial',
                'activo:id,nombre,tipo_control,categoria_id,tipo_activo_id',
                'activo.categoriaActivo:id,nombre',
                'activo.tipoActivo:id,nombre',
                'talla:id,valor',
            ])
            ->get();

        $inventarioPorEmpresa = $saldos
            ->groupBy('empresa_id')
            ->map(fn (Collection $filas): array => [
                'empresa' => [
                    'id' => (int) $filas->first()->empresa_id,
                    'nombre_comercial' => $filas->first()->empresa?->nombre_comercial,
                ],
                'total' => (int) $filas->sum('cantidad'),
                'bajo_minimo' => $filas->contains(fn (SaldoInventario $s): bool => $s->estaBajoMinimo()),
                'activos' => $this->resumirInventario($filas),
            ])
            ->sortBy('empresa.nombre_comercial')
            ->values()
            ->all();

        return Inertia::render('Almacenes/Detalle', [
            'resumen' => [
                'empresas_abastecidas' => $almacen->empresas->count(),
                'tipos_activo' => $saldos->pluck('activo_id')->unique()->count(),
                'existencias' => (int) $saldos->sum('cantidad'),
                'variantes_bajo_minimo' => $saldos->filter(fn (SaldoInventario $s): bool => $s->estaBajoMinimo())->count(),
            ],
            'inventarioPorEmpresa' => $inventarioPorEmpresa,
            'almacen' => [
                ...$almacen->only(['id', 'nombre', 'codigo', 'descripcion', 'direccion', 'telefono', 'correo', 'activo']),
                'empresas' => $almacen->empresas->map(fn ($e): array => [
                    'id' => $e->id, 'codigo' => $e->codigo, 'nombre_comercial' => $e->nombre_comercial,
                ])->all(),
                'responsable' => $almacen->responsable === null ? null : [
                    'id' => $almacen->responsable->id,
                    'nombre_completo' => $almacen->responsable->nombre_completo,
                    'numero_empleado' => $almacen->responsable->numero_empleado,
                    'area' => $almacen->responsable->departamento?->nombre,
                ],
            ],
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
            'permisos' => [
                'editar' => $request->user()->can('update', $almacen),
                'administrar' => $request->user()->can('administrar', $almacen),
                'inventario_ver' => $request->user()->can('inventario.ver'),
                'inventario_entrada' => $request->user()->can('inventario.entrada'),
                'inventario_ajustar' => $request->user()->can('inventario.ajustar'),
            ],
        ]);
    }

    public function store(GuardarAlmacenRequest $request): RedirectResponse
    {
        $datos = $request->safe()->except(['empresa_ids']);
        $empresaIds = $request->collect('empresa_ids')->map(fn ($id): int => (int) $id)->all();
        $datos['codigo'] = ($datos['codigo'] ?? null) ?: $this->generarCodigo();

        $almacen = Almacen::query()->create([...$datos, 'activo' => true]);
        $almacen->empresas()->sync($empresaIds);

        $this->auditar('crear', $almacen, $empresaIds, ['descripcion' => 'Alta de almacén '.$almacen->nombre]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Almacén registrado correctamente.']);
    }

    public function update(GuardarAlmacenRequest $request, Almacen $almacen): RedirectResponse
    {
        $almacen->update($request->safe()->except(['empresa_ids']));

        $empresaIds = $request->collect('empresa_ids')->map(fn ($id): int => (int) $id)->sort()->values();
        $antes = $almacen->empresas()->pluck('empresas.id')->sort()->values();

        if ($request->has('empresa_ids') && $antes->all() !== $empresaIds->all()) {
            $almacen->empresas()->sync($empresaIds->all());
            $this->auditar('empresas', $almacen, $empresaIds->merge($antes)->unique()->all(), [
                'descripcion' => 'Actualización de empresas abastecidas de '.$almacen->nombre,
                'valores_anteriores' => ['empresas' => $antes->all()],
                'valores_nuevos' => ['empresas' => $empresaIds->all()],
            ]);
        }

        $this->auditar('editar', $almacen, $almacen->empresas()->pluck('empresas.id')->all(), [
            'descripcion' => 'Edición de almacén '.$almacen->nombre,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Almacén actualizado correctamente.']);
    }

    public function toggle(Almacen $almacen): RedirectResponse
    {
        $this->authorize('administrar', $almacen);

        $almacen->update(['activo' => ! $almacen->activo]);

        $this->auditar($almacen->activo ? 'activar' : 'desactivar', $almacen, $almacen->empresas()->pluck('empresas.id')->all(), [
            'descripcion' => ($almacen->activo ? 'Activación' : 'Desactivación').' de almacén '.$almacen->nombre,
        ]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $almacen->activo ? 'Almacén activado correctamente.' : 'Almacén desactivado correctamente.',
        ]);
    }

    /**
     * Búsqueda con autocompletado de almacenes para los combobox (entrada de
     * inventario, entregas, filtros). Devuelve SIEMPRE sólo almacenes activos a
     * los que el usuario tiene acceso vía `almacen_empresa`.
     *
     * Regla de negocio: un almacén sólo puede usarse para una empresa si existe
     * la fila `almacen_empresa (almacen_id, empresa_id)`. Por eso, cuando llega
     * `empresa_id`:
     * - si es válido y autorizado → se acota ESTRICTAMENTE a los almacenes que
     *   abastecen esa empresa (`scopeParaEmpresa`);
     * - si viene pero es inválido o fuera de alcance → se devuelve lista vacía
     *   (nunca se cae de vuelta a "todas mis empresas": eso mostraría almacenes
     *   de otras empresas).
     *
     * Sin `empresa_id` (filtros genéricos) → todos los almacenes activos
     * autorizados.
     */
    public function buscar(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Almacen::class);

        $termino = trim((string) $request->query('q', ''));
        $idsAutorizadas = $this->idsEmpresasAutorizadas($request);

        $consulta = Almacen::query()
            ->where('activo', true)
            ->whereHas('empresas', fn (Builder $q) => $q->whereIn('empresas.id', $idsAutorizadas));

        if ($request->filled('empresa_id')) {
            $empresa = $this->empresaDelFiltro($request);

            if ($empresa === null) {
                return response()->json(['almacenes' => []]);
            }

            $consulta->paraEmpresa($empresa->id);
        }

        $almacenes = $consulta
            ->when($termino !== '', function (Builder $q) use ($termino): void {
                $q->where(function (Builder $sub) use ($termino): void {
                    $sub->where('nombre', 'like', "%{$termino}%")
                        ->orWhere('codigo', 'like', "%{$termino}%")
                        ->orWhere('direccion', 'like', "%{$termino}%");
                });
            })
            ->orderBy('nombre')
            ->limit(20)
            ->get(['id', 'nombre', 'codigo', 'direccion'])
            ->map(fn (Almacen $a): array => [
                'id' => $a->id,
                'nombre' => $a->nombre,
                'codigo' => $a->codigo,
                'direccion' => $a->direccion,
            ]);

        return response()->json(['almacenes' => $almacenes]);
    }

    /**
     * Búsqueda con autocompletado para el selector de responsable del almacén.
     * Requiere `empresa_id`: el responsable pertenece a una empresa concreta.
     */
    public function colaboradoresBuscar(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Almacen::class);

        $empresa = $this->empresaDelFiltro($request);

        if ($empresa === null) {
            return response()->json(['colaboradores' => []]);
        }

        $termino = trim((string) $request->query('q', ''));

        $colaboradores = Colaborador::query()
            ->where('empresa_id', $empresa->id)
            ->where('activo', true)
            ->when($termino !== '', function (Builder $q) use ($termino): void {
                $q->where(function (Builder $sub) use ($termino): void {
                    $sub->where('nombre_completo', 'like', "%{$termino}%")
                        ->orWhere('numero_empleado', 'like', "%{$termino}%");
                });
            })
            ->orderBy('nombre_completo')
            ->limit(20)
            ->get(['id', 'nombre_completo', 'numero_empleado'])
            ->map(fn (Colaborador $c): array => [
                'id' => $c->id,
                'nombre_completo' => $c->nombre_completo,
                'numero_empleado' => $c->numero_empleado,
            ]);

        return response()->json(['colaboradores' => $colaboradores]);
    }

    /**
     * Registra una entrada de auditoría por cada empresa abastecida afectada,
     * para que el filtro por empresa de la bitácora las muestre.
     *
     * @param  array<int, int|string>  $empresaIds
     * @param  array<string, mixed>  $extra
     */
    private function auditar(string $accion, Almacen $almacen, array $empresaIds, array $extra = []): void
    {
        foreach (array_unique(array_map('intval', $empresaIds)) as $empresaId) {
            $this->auditoria->registrar('almacenes', $accion, [
                'tipo_entidad' => Almacen::class,
                'entidad_id' => $almacen->id,
                'empresa_id' => $empresaId,
                ...$extra,
            ]);
        }
    }

    /**
     * Agrupa los saldos por activo para el detalle.
     *
     * @param  Collection<int, SaldoInventario>  $saldos
     * @return array<int, array<string, mixed>>
     */
    private function resumirInventario(Collection $saldos): array
    {
        return $saldos
            ->groupBy('activo_id')
            ->map(function (Collection $filas): array {
                $activo = $filas->first()?->activo;

                $variantes = $filas
                    ->map(fn (SaldoInventario $s): array => [
                        'talla_id' => (int) $s->talla_id,
                        'talla' => $s->talla?->valor,
                        'cantidad' => (int) $s->cantidad,
                        'minimo' => (int) $s->minimo,
                        'bajo_minimo' => $s->estaBajoMinimo(),
                    ])
                    ->sortBy('talla')
                    ->values()
                    ->all();

                return [
                    'activo_id' => $activo?->id,
                    'activo' => $activo?->nombre,
                    'tipo' => $activo?->tipoActivo?->nombre,
                    'categoria' => $activo?->categoriaActivo?->nombre,
                    'control' => $activo?->tipo_control->value,
                    'total' => (int) $filas->sum('cantidad'),
                    'bajo_minimo' => $filas->contains(fn (SaldoInventario $s): bool => $s->estaBajoMinimo()),
                    'variantes' => $variantes,
                ];
            })
            ->sortBy('activo')
            ->values()
            ->all();
    }

    /**
     * Genera un código consecutivo y único a nivel plataforma (ALM-0001, …)
     * cuando el usuario no captura uno. El almacén ya no pertenece a una empresa.
     */
    private function generarCodigo(): string
    {
        $n = Almacen::query()->withTrashed()->count() + 1;

        do {
            $codigo = 'ALM-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            $n++;
        } while (Almacen::query()->withTrashed()->where('codigo', $codigo)->exists());

        return $codigo;
    }
}
