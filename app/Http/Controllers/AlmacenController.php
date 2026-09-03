<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConEmpresaActiva;
use App\Http\Requests\Almacenes\GuardarAlmacenRequest;
use App\Models\Almacen;
use App\Models\Colaborador;
use App\Models\SaldoInventario;
use App\Servicios\ServicioAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AlmacenController extends Controller
{
    use ConEmpresaActiva;

    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Almacen::class);

        $empresa = $this->empresaActiva();
        $usuario = $request->user();

        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', Rule::in(['activos', 'inactivos'])],
            'orden' => ['nullable', Rule::in(['az', 'za'])],
        ]);

        $orden = ($filtros['orden'] ?? 'az') === 'za' ? 'desc' : 'asc';

        $almacenes = Almacen::query()
            ->where('empresa_id', $empresa->id)
            ->with('responsable:id,nombre_completo,numero_empleado')
            ->withCount('sucursales')
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
                'sucursales_count' => (int) $a->sucursales_count,
                'responsable' => $a->responsable === null ? null : [
                    'id' => $a->responsable->id,
                    'nombre_completo' => $a->responsable->nombre_completo,
                    'numero_empleado' => $a->responsable->numero_empleado,
                ],
            ]);

        return Inertia::render('Almacenes/Index', [
            'almacenes' => $almacenes,
            'empresa' => ['id' => $empresa->id, 'nombre_comercial' => $empresa->nombre_comercial],
            'sucursales' => $this->sucursalesEmpresa(),
            'filtros' => [
                'buscar' => $filtros['buscar'] ?? '',
                'estado' => $filtros['estado'] ?? '',
                'orden' => $filtros['orden'] ?? 'az',
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
        abort_unless($almacen->empresa_id === $this->empresaActiva()->id, 404);

        $almacen->load([
            'responsable:id,nombre_completo,numero_empleado,area_id',
            'responsable.departamento:id,nombre',
            'sucursales:id,nombre,codigo,direccion,activa',
        ]);

        $saldos = SaldoInventario::query()
            ->where('empresa_id', $almacen->empresa_id)
            ->where('almacen_id', $almacen->id)
            ->with(['activo:id,nombre,tipo_control,categoria_id,tipo_activo_id', 'activo.categoriaActivo:id,nombre', 'activo.tipoActivo:id,nombre', 'talla:id,valor'])
            ->get();

        $inventario = $this->resumirInventario($saldos);

        $legacyPendiente = SaldoInventario::query()
            ->where('empresa_id', $almacen->empresa_id)
            ->pendienteMigracion()
            ->whereHas('sucursal', fn (Builder $q) => $q->whereHas('almacenes', fn (Builder $a) => $a->whereKey($almacen->id)))
            ->count();

        return Inertia::render('Almacenes/Detalle', [
            'resumen' => [
                'tipos_activo' => count($inventario),
                'existencias' => (int) $saldos->sum('cantidad'),
                'variantes_bajo_minimo' => $saldos->filter(fn (SaldoInventario $s): bool => $s->estaBajoMinimo())->count(),
                'sucursales_abastecidas' => $almacen->sucursales->count(),
                'legacy_pendiente' => $legacyPendiente,
            ],
            'inventario' => $inventario,
            'almacen' => [
                ...$almacen->only(['id', 'nombre', 'codigo', 'descripcion', 'direccion', 'telefono', 'correo', 'activo']),
                'empresa' => [
                    'id' => $this->empresaActiva()->id,
                    'nombre_comercial' => $this->empresaActiva()->nombre_comercial,
                ],
                'responsable' => $almacen->responsable === null ? null : [
                    'id' => $almacen->responsable->id,
                    'nombre_completo' => $almacen->responsable->nombre_completo,
                    'numero_empleado' => $almacen->responsable->numero_empleado,
                    'area' => $almacen->responsable->departamento?->nombre,
                ],
                'sucursales' => $almacen->sucursales->map(fn ($s): array => [
                    'id' => $s->id,
                    'nombre' => $s->nombre,
                    'codigo' => $s->codigo,
                    'direccion' => $s->direccion,
                    'activa' => $s->activa,
                ])->all(),
            ],
            'sucursalesDisponibles' => $this->sucursalesEmpresa(),
            'permisos' => [
                'editar' => $request->user()->can('update', $almacen),
                'administrar' => $request->user()->can('administrar', $almacen),
                'inventario_ver' => $request->user()->can('inventario.ver'),
                'inventario_entrada' => $request->user()->can('inventario.entrada'),
                'inventario_ajustar' => $request->user()->can('inventario.ajustar'),
                'inventario_migrar' => $request->user()->can('inventario.migrar'),
            ],
        ]);
    }

    public function store(GuardarAlmacenRequest $request): RedirectResponse
    {
        $empresa = $this->empresaActiva();

        $datos = $request->safe()->except(['sucursales']);
        $datos['codigo'] = ($datos['codigo'] ?? null) ?: $this->generarCodigo($empresa->id);

        $almacen = Almacen::query()->create([
            ...$datos,
            'empresa_id' => $empresa->id,
            'activo' => true,
        ]);

        $almacen->sucursales()->sync($request->input('sucursales', []));

        $this->auditoria->registrar('almacenes', 'crear', [
            'tipo_entidad' => Almacen::class, 'entidad_id' => $almacen->id,
            'descripcion' => 'Alta de almacén '.$almacen->nombre,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Almacén registrado correctamente.']);
    }

    public function update(GuardarAlmacenRequest $request, Almacen $almacen): RedirectResponse
    {
        abort_unless($almacen->empresa_id === $this->empresaActiva()->id, 404);

        $almacen->update($request->safe()->except(['sucursales']));

        if ($request->has('sucursales')) {
            $antes = $almacen->sucursales()->pluck('sucursales.id')->sort()->values()->all();
            $almacen->sucursales()->sync($request->input('sucursales', []));
            $despues = $almacen->sucursales()->pluck('sucursales.id')->sort()->values()->all();

            if ($antes !== $despues) {
                $this->auditoria->registrar('almacenes', 'sucursales', [
                    'tipo_entidad' => Almacen::class, 'entidad_id' => $almacen->id,
                    'descripcion' => 'Actualización de sucursales abastecidas de '.$almacen->nombre,
                    'valores_anteriores' => ['sucursales' => $antes],
                    'valores_nuevos' => ['sucursales' => $despues],
                ]);
            }
        }

        $this->auditoria->registrar('almacenes', 'editar', [
            'tipo_entidad' => Almacen::class, 'entidad_id' => $almacen->id,
            'descripcion' => 'Edición de almacén '.$almacen->nombre,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Almacén actualizado correctamente.']);
    }

    /**
     * Sincroniza únicamente las sucursales abastecidas (flujo "Asignar
     * sucursales" desde el detalle).
     */
    public function sucursales(Request $request, Almacen $almacen): RedirectResponse
    {
        $this->authorize('administrar', $almacen);
        abort_unless($almacen->empresa_id === $this->empresaActiva()->id, 404);

        $empresaId = $this->empresaActiva()->id;

        $datos = $request->validate([
            'sucursales' => ['nullable', 'array'],
            'sucursales.*' => [
                'integer',
                Rule::exists('sucursales', 'id')->where(fn ($q) => $q->where('empresa_id', $empresaId)),
            ],
        ], [
            'sucursales.*.exists' => 'Una de las sucursales seleccionadas no pertenece a esta empresa.',
        ]);

        $antes = $almacen->sucursales()->pluck('sucursales.id')->sort()->values()->all();
        $almacen->sucursales()->sync($datos['sucursales'] ?? []);
        $despues = $almacen->sucursales()->pluck('sucursales.id')->sort()->values()->all();

        $this->auditoria->registrar('almacenes', 'sucursales', [
            'tipo_entidad' => Almacen::class, 'entidad_id' => $almacen->id,
            'descripcion' => 'Actualización de sucursales abastecidas de '.$almacen->nombre,
            'valores_anteriores' => ['sucursales' => $antes],
            'valores_nuevos' => ['sucursales' => $despues],
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Sucursales abastecidas actualizadas.']);
    }

    public function toggle(Almacen $almacen): RedirectResponse
    {
        $this->authorize('administrar', $almacen);
        abort_unless($almacen->empresa_id === $this->empresaActiva()->id, 404);

        $almacen->update(['activo' => ! $almacen->activo]);

        $this->auditoria->registrar('almacenes', $almacen->activo ? 'activar' : 'desactivar', [
            'tipo_entidad' => Almacen::class, 'entidad_id' => $almacen->id,
            'descripcion' => ($almacen->activo ? 'Activación' : 'Desactivación').' de almacén '.$almacen->nombre,
        ]);

        $mensaje = $almacen->activo
            ? 'Almacén activado correctamente.'
            : 'Almacén desactivado correctamente.';

        return back()->with('toast', ['type' => 'success', 'message' => $mensaje]);
    }

    /**
     * Búsqueda con autocompletado para el selector de responsable del almacén.
     * Sólo colaboradores activos de la empresa activa.
     */
    public function colaboradoresBuscar(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Almacen::class);

        $termino = trim((string) $request->query('q', ''));

        $colaboradores = Colaborador::query()
            ->where('empresa_id', $this->empresaActiva()->id)
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
     * Agrupa los saldos del almacén por activo para el detalle.
     *
     * @param  Collection<int, SaldoInventario>  $saldos
     * @return array<int, array<string, mixed>>
     */
    private function resumirInventario(Collection $saldos): array
    {
        return $saldos
            ->groupBy('activo_id')
            ->map(function (Collection $filas): array {
                /** @var SaldoInventario|null $primero */
                $primero = $filas->first();
                $activo = $primero?->activo;

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
     * @return array<int, array{id: int, nombre: string, codigo: string, activa: bool}>
     */
    private function sucursalesEmpresa(): array
    {
        return $this->empresaActiva()->sucursales()
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'codigo', 'activa'])
            ->map(fn ($s): array => [
                'id' => $s->id,
                'nombre' => $s->nombre,
                'codigo' => $s->codigo,
                'activa' => (bool) $s->activa,
            ])->all();
    }

    /**
     * Genera un código consecutivo y único dentro de la empresa
     * (ALM-0001, ALM-0002, …) cuando el usuario no captura uno.
     */
    private function generarCodigo(int $empresaId): string
    {
        $n = Almacen::query()->withTrashed()->where('empresa_id', $empresaId)->count() + 1;

        do {
            $codigo = 'ALM-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            $n++;
        } while (Almacen::query()->withTrashed()->where('empresa_id', $empresaId)->where('codigo', $codigo)->exists());

        return $codigo;
    }
}
