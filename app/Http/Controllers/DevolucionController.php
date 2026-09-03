<?php

namespace App\Http\Controllers;

use App\Acciones\RegistrarDevolucion;
use App\Enums\CondicionDevolucion;
use App\Http\Controllers\Concerns\ConEmpresa;
use App\Models\Activo;
use App\Models\Colaborador;
use App\Models\Devolucion;
use App\Models\Empresa;
use App\Models\Talla;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Devoluciones de activos. La empresa se DERIVA del colaborador. El almacén
 * destino se resuelve con ResolverAlmacenOperativo::paraEmpresa (Bloque F
 * reharás esta UI con selector de almacén).
 */
class DevolucionController extends Controller
{
    use ConEmpresa;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Devolucion::class);

        $idsAutorizadas = $this->idsEmpresasAutorizadas($request);
        $empresaFiltro = $this->empresaDelFiltro($request);

        $devoluciones = Devolucion::query()
            ->whereIn('empresa_id', $idsAutorizadas)
            ->when($empresaFiltro !== null, fn ($q) => $q->where('empresa_id', $empresaFiltro->id))
            ->with(['colaborador:id,nombre_completo,numero_empleado', 'sucursal:id,nombre', 'empresa:id,nombre_comercial', 'registradaPor:id,name'])
            ->withCount('detalles')
            ->latest()
            ->paginate($this->porPagina())
            ->withQueryString()
            ->through(fn (Devolucion $d): array => [
                'id' => $d->id,
                'folio' => $d->folio,
                'empresa' => $d->empresa?->nombre_comercial,
                'colaborador' => $d->colaborador?->nombre_completo,
                'sucursal' => $d->sucursal?->nombre,
                'registrada_por' => $d->registradaPor?->name,
                'fecha' => $d->fecha->toDateString(),
                'renglones' => $d->detalles_count,
            ]);

        return Inertia::render('Devoluciones/Index', [
            'devoluciones' => $devoluciones,
            'filtros' => ['empresa_id' => $empresaFiltro?->id],
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
            'puedeCrear' => $request->user()->can('create', Devolucion::class),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Devolucion::class);

        $empresas = $this->empresasAutorizadas($request);

        $colaboradores = Colaborador::query()
            ->whereIn('empresa_id', $empresas->pluck('id'))
            ->with(['sucursal:id,nombre', 'empresa:id,nombre_comercial'])
            ->orderBy('nombre_completo')
            ->get()
            ->map(fn (Colaborador $c): array => [
                'id' => $c->id,
                'nombre_completo' => $c->nombre_completo,
                'numero_empleado' => $c->numero_empleado,
                'empresa_id' => $c->empresa_id,
                'empresa' => $c->empresa?->nombre_comercial,
                'sucursal_id' => $c->sucursal_id,
                'sucursal' => $c->sucursal?->nombre,
            ]);

        $activosPorEmpresa = [];
        foreach ($empresas as $empresa) {
            $activosPorEmpresa[$empresa->id] = $this->activosDeEmpresa($empresa);
        }

        return Inertia::render('Devoluciones/Crear', [
            'colaboradores' => $colaboradores,
            'activosPorEmpresa' => $activosPorEmpresa,
            'condiciones' => collect(CondicionDevolucion::cases())->map(fn ($c): array => ['valor' => $c->value, 'etiqueta' => $c->etiqueta()]),
        ]);
    }

    /**
     * @return array<int, array{id: int, nombre: string, tallas: array<int, mixed>}>
     */
    private function activosDeEmpresa(Empresa $empresa): array
    {
        return $empresa->activos()
            ->where('tipo_control', 'cantidad')
            ->with('tallas:id,valor')
            ->orderBy('nombre')
            ->get()
            ->map(fn (Activo $a): array => [
                'id' => $a->id,
                'nombre' => $a->nombre,
                'tallas' => $a->tallas->map(fn (Talla $t): array => ['id' => $t->id, 'valor' => $t->valor])->all(),
            ])
            ->all();
    }

    public function store(Request $request, RegistrarDevolucion $accion): RedirectResponse
    {
        $this->authorize('create', Devolucion::class);

        $colaborador = Colaborador::findOrFail($request->integer('colaborador_id'));
        abort_unless($request->user()->puedeAccederEmpresa($colaborador->empresa_id), 403, 'No tienes acceso a la empresa de ese colaborador.');
        $empresaId = $colaborador->empresa_id;

        $datos = $request->validate([
            'colaborador_id' => ['required', 'integer'],
            'entrega_uniforme_id' => ['nullable', 'integer'],
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'motivo' => ['nullable', 'string', 'max:255'],
            'notas' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.activo_id' => ['required', 'integer', Rule::exists('activos', 'id')->where(fn ($q) => $q->where('empresa_id', $empresaId))],
            'items.*.talla_id' => ['required', 'integer', Rule::exists('tallas', 'id')->where(fn ($q) => $q->where('empresa_id', $empresaId))],
            'items.*.cantidad' => ['required', 'integer', 'min:1', 'max:1000'],
            'items.*.condicion' => ['required', Rule::enum(CondicionDevolucion::class)],
        ]);

        $devolucion = $accion->ejecutar(
            $empresaId,
            $colaborador->sucursal_id,
            $colaborador->id,
            $datos['entrega_uniforme_id'] ?? null,
            $datos['fecha'],
            $datos['items'],
            $request->user()->id,
            $datos['motivo'] ?? null,
            $datos['notas'] ?? null,
        );

        return to_route('devoluciones.index')->with('toast', [
            'type' => 'success', 'message' => "Devolución {$devolucion->folio} registrada.",
        ]);
    }
}
