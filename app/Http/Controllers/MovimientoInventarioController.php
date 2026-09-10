<?php

namespace App\Http\Controllers;

use App\Acciones\RegistrarTraspasoInventario;
use App\Enums\TipoMovimiento;
use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Controllers\Concerns\ExportaListado;
use App\Http\Requests\Inventario\RegistrarTraspasoRequest;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\MovimientoInventario;
use App\Models\TraspasoInventario;
use App\Servicios\HomologadorActivo;
use App\Soporte\ContextoExportacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Historial de movimientos de inventario por empresa. Filtros por empresa y
 * almacén (búsqueda). `sucursal_id` sólo aparece como procedencia histórica.
 */
class MovimientoInventarioController extends Controller
{
    use ConEmpresa;
    use ExportaListado;

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('inventario.ver'), 403);

        $usuario = $request->user();
        $idsScope = $this->idsScope($request);
        $empresaFiltro = $this->empresaDelFiltro($request);
        $filtros = $this->filtrosListado($request);

        $movimientos = $this->consultaMovimientos($request, $filtros)
            ->paginate($this->porPagina())
            ->withQueryString()
            ->through(fn (MovimientoInventario $m): array => [
                'id' => $m->id,
                'empresa' => $m->empresa?->nombre_comercial,
                'tipo' => $m->tipo->value,
                'tipo_etiqueta' => $m->tipo->etiqueta(),
                'direccion' => $m->direccion->value,
                'cantidad' => $m->cantidad,
                'existencia_anterior' => $m->existencia_anterior,
                'existencia_resultante' => $m->existencia_resultante,
                'almacen' => $m->almacen?->nombre,
                'sucursal' => $m->sucursal?->nombre,
                'activo' => $m->activo?->nombre,
                'talla' => $m->talla?->valor,
                'motivo' => $m->motivo,
                'realizado_por' => $m->realizadoPor?->name,
                'ocurrido_en' => $m->ocurrido_en->toIso8601String(),
            ]);

        return Inertia::render('Inventario/Movimientos', [
            'movimientos' => $movimientos,
            'filtros' => [...$filtros, 'empresa_id' => $empresaFiltro?->id],
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
            'almacenes' => $idsScope
                ->flatMap(fn (int $id): array => $this->acceso()->almacenesAutorizados($usuario, $id)->all())
                ->unique('id')->map->only(['id', 'nombre'])->values(),
            'tipos' => collect(TipoMovimiento::cases())->map(fn ($t): array => ['valor' => $t->value, 'etiqueta' => $t->etiqueta()]),
            'puedeTransferir' => $request->user()->can('inventario.transferir'),
        ]);
    }

    /**
     * Formulario "Nuevo traspaso" (dentro del módulo Movimientos, no un módulo
     * aparte). El historial existente no se toca.
     */
    public function nuevoTraspaso(Request $request): Response
    {
        abort_unless($request->user()->can('inventario.transferir'), 403);

        return Inertia::render('Inventario/Traspasos/Crear', [
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
        ]);
    }

    /**
     * Previsualización NO autoritativa del Activo destino de cada renglón: por
     * cada activo de origen indica si en la empresa destino ya existe un
     * equivalente inequívoco, si hay varios candidatos (ambiguo → el usuario
     * elige) o si se creará uno nuevo al confirmar. NO crea nada.
     */
    public function previsualizarTraspaso(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('inventario.transferir'), 403);

        $datos = $request->validate([
            'empresa_origen_id' => ['required', 'integer'],
            'empresa_destino_id' => ['required', 'integer'],
            'activo_ids' => ['required', 'array', 'max:100'],
            'activo_ids.*' => ['integer'],
        ]);

        $usuario = $request->user();
        if (! $usuario->puedeAccederEmpresa((int) $datos['empresa_origen_id']) || ! $usuario->puedeAccederEmpresa((int) $datos['empresa_destino_id'])) {
            return response()->json(['renglones' => []]);
        }

        $homologador = app(HomologadorActivo::class);
        $destinoId = (int) $datos['empresa_destino_id'];

        $activos = Activo::query()
            ->where('empresa_id', (int) $datos['empresa_origen_id'])
            ->whereIn('id', $datos['activo_ids'])
            ->with('tallas:id')
            ->get();

        $renglones = $activos->map(function (Activo $origen) use ($homologador, $destinoId): array {
            $candidatos = $homologador->candidatos($origen, $destinoId);

            return [
                'activo_origen_id' => $origen->id,
                'candidatos' => $candidatos->map(fn (Activo $c): array => ['id' => $c->id, 'codigo' => $c->codigo, 'nombre' => $c->nombre])->all(),
                'ambiguo' => $candidatos->count() > 1,
                'se_creara' => $candidatos->isEmpty(),
                'activo_destino' => $candidatos->count() === 1
                    ? ['id' => $candidatos->first()->id, 'codigo' => $candidatos->first()->codigo, 'nombre' => $candidatos->first()->nombre]
                    : null,
            ];
        });

        return response()->json(['renglones' => $renglones]);
    }

    public function almacenarTraspaso(RegistrarTraspasoRequest $request, RegistrarTraspasoInventario $accion): RedirectResponse
    {
        $datos = $request->validated();

        $traspaso = $accion->ejecutar(
            (int) $datos['empresa_origen_id'],
            (int) $datos['almacen_origen_id'],
            (int) $datos['empresa_destino_id'],
            (int) $datos['almacen_destino_id'],
            $datos['renglones'],
            $request->user()->id,
            $datos['motivo'] ?? null,
            $datos['notas'] ?? null,
        );

        return to_route('inventario.movimientos')->with('toast', [
            'type' => 'success',
            'message' => "Traspaso {$traspaso->folio} registrado correctamente.",
        ]);
    }

    /**
     * Reconstrucción de un traspaso: encabezado + renglones + movimientos
     * correlacionados (qué salió / qué entró = mismo traspaso).
     */
    public function traspasoShow(Request $request, TraspasoInventario $traspaso): Response
    {
        $this->authorize('view', $traspaso);

        $traspaso->load([
            'empresaOrigen:id,nombre_comercial',
            'empresaDestino:id,nombre_comercial',
            'almacenOrigen:id,nombre',
            'almacenDestino:id,nombre',
            'realizadoPor:id,name',
            'renglones.activoOrigen:id,nombre,codigo',
            'renglones.activoDestino:id,nombre,codigo',
            'renglones.talla:id,valor',
        ]);

        return Inertia::render('Inventario/Traspasos/Detalle', [
            'traspaso' => [
                'id' => $traspaso->id,
                'folio' => $traspaso->folio,
                'tipo' => $traspaso->tipo,
                'estado' => $traspaso->estado,
                'motivo' => $traspaso->motivo,
                'notas' => $traspaso->notas,
                'ocurrido_en' => $traspaso->ocurrido_en->toIso8601String(),
                'realizado_por' => $traspaso->realizadoPor?->name,
                'empresa_origen' => $traspaso->empresaOrigen?->nombre_comercial,
                'almacen_origen' => $traspaso->almacenOrigen?->nombre,
                'empresa_destino' => $traspaso->empresaDestino?->nombre_comercial,
                'almacen_destino' => $traspaso->almacenDestino?->nombre,
                'renglones' => $traspaso->renglones->map(fn ($r): array => [
                    'id' => $r->id,
                    'control' => $r->control->value,
                    'activo_origen' => $r->activo_origen_nombre_snapshot,
                    'activo_destino' => $r->activo_destino_nombre_snapshot,
                    'activo_destino_codigo' => $r->activoDestino?->codigo,
                    'activo_destino_creado' => $r->activo_destino_creado,
                    'talla' => $r->talla_valor_snapshot,
                    'cantidad' => $r->cantidad,
                    'unidad_codigo' => $r->unidad_codigo_snapshot,
                    'movimiento_salida_id' => $r->movimiento_salida_id,
                    'movimiento_entrada_id' => $r->movimiento_entrada_id,
                ]),
            ],
        ]);
    }

    /**
     * Excel/PDF del listado, respetando los mismos filtros que `index()`.
     */
    public function exportar(Request $request): BinaryFileResponse|HttpResponse
    {
        abort_unless($request->user()->can('inventario.ver'), 403);

        $filtros = $this->filtrosListado($request);
        $empresaFiltro = $this->empresaDelFiltro($request);
        $movimientos = $this->consultaMovimientos($request, $filtros)->get();

        $filas = $movimientos->map(fn (MovimientoInventario $m): array => [
            $m->ocurrido_en->format('d/m/Y H:i'),
            $m->empresa?->nombre_comercial,
            $m->tipo->etiqueta(),
            $m->direccion->value,
            $m->cantidad,
            $m->existencia_anterior,
            $m->existencia_resultante,
            $m->almacen?->nombre,
            $m->sucursal?->nombre,
            $m->activo?->nombre,
            $m->talla?->valor,
            $m->motivo,
            $m->realizadoPor?->name,
        ])->all();

        $filtrosHumanos = array_filter([
            'Almacén' => ($filtros['almacen_id'] ?? null) ? Almacen::query()->find((int) $filtros['almacen_id'])?->nombre : null,
            'Tipo' => ($filtros['tipo'] ?? null) ? (TipoMovimiento::tryFrom($filtros['tipo'])?->etiqueta() ?? $filtros['tipo']) : null,
            'Desde' => ($filtros['desde'] ?? null) ? Carbon::parse($filtros['desde'])->format('d/m/Y') : null,
            'Hasta' => ($filtros['hasta'] ?? null) ? Carbon::parse($filtros['hasta'])->format('d/m/Y') : null,
        ]);

        $contexto = new ContextoExportacion('Movimientos de inventario', $empresaFiltro, $filtrosHumanos, $movimientos->count());

        return $this->respuestaExportacion($request->input('formato', 'xlsx'), $filas, [
            'Fecha', 'Empresa', 'Tipo', 'Dirección', 'Cantidad', 'Existencia anterior', 'Existencia resultante',
            'Almacén', 'Sucursal', 'Activo', 'Talla', 'Motivo', 'Realizó',
        ], $contexto);
    }

    /**
     * @return Collection<int, int>
     */
    private function idsScope(Request $request): Collection
    {
        $idsAutorizadas = $this->idsEmpresasAutorizadas($request);
        $empresaFiltro = $this->empresaDelFiltro($request);

        return $empresaFiltro !== null ? collect([$empresaFiltro->id]) : $idsAutorizadas;
    }

    /**
     * @return array<string, mixed>
     */
    private function filtrosListado(Request $request): array
    {
        return $request->validate([
            'almacen_id' => ['nullable', 'integer'],
            'activo_id' => ['nullable', 'integer'],
            'tipo' => ['nullable', 'string'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Builder<MovimientoInventario>
     */
    private function consultaMovimientos(Request $request, array $filtros): Builder
    {
        $usuario = $request->user();
        $idsScope = $this->idsScope($request);

        $almacenesVisibles = $idsScope
            ->flatMap(fn (int $id): array => $this->acceso()->almacenesAutorizados($usuario, $id)->pluck('id')->all())
            ->unique()->values();

        return MovimientoInventario::query()
            ->whereIn('empresa_id', $idsScope)
            ->where(function (Builder $q) use ($almacenesVisibles): void {
                $q->whereIn('almacen_id', $almacenesVisibles)->orWhereNull('almacen_id');
            })
            ->when($filtros['almacen_id'] ?? null, fn (Builder $q, $v) => $q->where('almacen_id', $v))
            ->when($filtros['activo_id'] ?? null, fn (Builder $q, $v) => $q->where('activo_id', $v))
            ->when($filtros['tipo'] ?? null, fn (Builder $q, $t) => $q->where('tipo', $t))
            ->when($filtros['desde'] ?? null, fn (Builder $q, $d) => $q->whereDate('ocurrido_en', '>=', $d))
            ->when($filtros['hasta'] ?? null, fn (Builder $q, $h) => $q->whereDate('ocurrido_en', '<=', $h))
            ->with(['empresa:id,nombre_comercial', 'almacen:id,nombre', 'sucursal:id,nombre', 'activo:id,nombre', 'talla:id,valor', 'realizadoPor:id,name'])
            ->latest('ocurrido_en');
    }
}
