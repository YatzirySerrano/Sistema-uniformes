<?php

namespace App\Http\Controllers;

use App\Acciones\RegistrarTraspasoFirmado;
use App\Enums\TipoMovimiento;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Controllers\Concerns\ExportaListado;
use App\Http\Requests\Inventario\RegistrarTraspasoRequest;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Devolucion;
use App\Models\EntregaUniforme;
use App\Models\MovimientoInventario;
use App\Models\TraspasoInventario;
use App\Models\User;
use App\Servicios\HomologadorActivo;
use App\Soporte\ContextoExportacion;
use App\Soporte\FechaHora;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

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

        $paginador = $this->consultaMovimientos($request, $filtros)
            ->paginate($this->porPagina())
            ->withQueryString();

        $foliosTraspaso = $this->foliosTraspaso($paginador->getCollection());

        $movimientos = $paginador->through(fn (MovimientoInventario $m): array => [
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
            'unidad_codigo' => $m->unidadActivo?->codigo,
            'motivo' => $m->motivo,
            'referencia' => $this->referenciaLegible($m, $foliosTraspaso),
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
     * "Traspasos de inventario": a diferencia de `index()` (el historial
     * técnico completo, que mezcla entradas/entregas/devoluciones/traspasos y
     * confunde al usuario final), esta vista SÓLO lista traspasos, consultando
     * `TraspasoInventario` como entidad raíz — NUNCA reconstruida agrupando
     * `movimientos_inventario` — así que cada traspaso aparece EXACTAMENTE
     * una vez (nunca como dos tarjetas "salida"/"entrada"). El alcance de
     * empresa es el mismo que autoriza verlo (`TraspasoInventarioPolicy`):
     * acceso a origen O destino.
     */
    public function indexTraspasos(Request $request): Response
    {
        abort_unless($request->user()->can('inventario.ver'), 403);

        $idsScope = $this->idsScope($request);
        $empresaFiltro = $this->empresaDelFiltro($request);
        $filtros = $this->filtrosTraspasos($request);

        $traspasos = $this->consultaTraspasos($request, $filtros)
            ->withCount('renglones')
            ->withSum('renglones', 'cantidad')
            ->paginate($this->porPagina())
            ->withQueryString()
            ->through(fn (TraspasoInventario $t): array => [
                'id' => $t->id,
                'folio' => $t->folio,
                'estado' => $t->estado,
                'interempresa' => $t->esInterempresa(),
                'empresa_origen' => $t->empresaOrigen?->nombre_comercial,
                'almacen_origen' => $t->almacenOrigen?->nombre,
                'empresa_destino' => $t->empresaDestino?->nombre_comercial,
                'almacen_destino' => $t->almacenDestino?->nombre,
                'renglones' => (int) $t->renglones_count,
                'unidades' => (int) ($t->renglones_sum_cantidad ?? 0),
                'realizado_por' => $t->realizadoPor?->name,
                'ocurrido_en' => $t->ocurrido_en->toIso8601String(),
            ]);

        return Inertia::render('Inventario/Traspasos/Index', [
            'traspasos' => $traspasos,
            'filtros' => [...$filtros, 'empresa_id' => $empresaFiltro?->id],
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
            'almacenes' => $idsScope
                ->flatMap(fn (int $id): array => $this->acceso()->almacenesAutorizados($request->user(), $id)->all())
                ->unique('id')->map->only(['id', 'nombre'])->values(),
            'puedeTransferir' => $request->user()->can('inventario.transferir'),
        ]);
    }

    /**
     * Excel/PDF de "Traspasos de inventario", respetando los mismos filtros
     * que `indexTraspasos()` — misma consulta filtrada, sólo cambia la salida.
     */
    public function exportarTraspasos(Request $request): BinaryFileResponse|HttpResponse
    {
        abort_unless($request->user()->can('inventario.ver'), 403);

        $filtros = $this->filtrosTraspasos($request);
        $empresaFiltro = $this->empresaDelFiltro($request);
        $traspasos = $this->consultaTraspasos($request, $filtros)
            ->withCount('renglones')
            ->withSum('renglones', 'cantidad')
            ->get();

        $filas = $traspasos->map(fn (TraspasoInventario $t): array => [
            $t->folio,
            $t->empresaOrigen?->nombre_comercial,
            $t->almacenOrigen?->nombre,
            $t->empresaDestino?->nombre_comercial,
            $t->almacenDestino?->nombre,
            (int) $t->renglones_count,
            (int) ($t->renglones_sum_cantidad ?? 0),
            $t->realizadoPor?->name,
            FechaHora::local($t->ocurrido_en),
        ])->all();

        $filtrosHumanos = array_filter([
            'Almacén' => ($filtros['almacen_id'] ?? null) ? Almacen::query()->find((int) $filtros['almacen_id'])?->nombre : null,
            'Desde' => ($filtros['desde'] ?? null) ? Carbon::parse($filtros['desde'])->format('d/m/Y') : null,
            'Hasta' => ($filtros['hasta'] ?? null) ? Carbon::parse($filtros['hasta'])->format('d/m/Y') : null,
        ]);

        $contexto = new ContextoExportacion('Traspasos de inventario', $empresaFiltro, $filtrosHumanos, $traspasos->count());

        return $this->respuestaExportacion($request->input('formato', 'xlsx'), $filas, [
            'Folio', 'Empresa origen', 'Almacén origen', 'Empresa destino', 'Almacén destino',
            'Renglones', 'Unidades', 'Realizó', 'Fecha',
        ], $contexto);
    }

    /**
     * @return array<string, mixed>
     */
    private function filtrosTraspasos(Request $request): array
    {
        return $request->validate([
            'almacen_id' => ['nullable', 'integer'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
        ]);
    }

    /**
     * Consulta de `TraspasoInventario` como entidad raíz — NUNCA reconstruida
     * agrupando `movimientos_inventario` — compartida por `indexTraspasos()`
     * y `exportarTraspasos()`. El alcance de empresa es el mismo que autoriza
     * verlos (`TraspasoInventarioPolicy::view`): acceso a origen O destino.
     *
     * @param  array<string, mixed>  $filtros
     * @return Builder<TraspasoInventario>
     */
    private function consultaTraspasos(Request $request, array $filtros): Builder
    {
        $idsScope = $this->idsScope($request);

        return TraspasoInventario::query()
            ->where(function (Builder $q) use ($idsScope): void {
                $q->whereIn('empresa_origen_id', $idsScope)->orWhereIn('empresa_destino_id', $idsScope);
            })
            ->when($filtros['almacen_id'] ?? null, fn (Builder $q, $v) => $q->where(
                fn (Builder $sub) => $sub->where('almacen_origen_id', $v)->orWhere('almacen_destino_id', $v)
            ))
            ->when($filtros['desde'] ?? null, fn (Builder $q, $d) => $q->whereDate('ocurrido_en', '>=', $d))
            ->when($filtros['hasta'] ?? null, fn (Builder $q, $h) => $q->whereDate('ocurrido_en', '<=', $h))
            ->with([
                'empresaOrigen:id,nombre_comercial', 'empresaDestino:id,nombre_comercial',
                'almacenOrigen:id,nombre', 'almacenDestino:id,nombre',
                'realizadoPor:id,name',
            ])
            ->latest('ocurrido_en');
    }

    /**
     * Detalle de un movimiento concreto. Reutiliza EXACTAMENTE la misma
     * consulta con scope que `index()` (`consultaMovimientos()`): si el
     * movimiento no aparece ahí (fuera de las empresas/almacenes autorizados
     * del usuario), responde 404 — nunca 403, para no confirmar por el código
     * de estado que el registro existe.
     */
    public function show(Request $request, MovimientoInventario $movimiento): Response
    {
        abort_unless($request->user()->can('inventario.ver'), 403);

        $filtros = $this->filtrosListado($request);
        $encontrado = $this->consultaMovimientos($request, $filtros)->whereKey($movimiento->getKey())->first();

        abort_if($encontrado === null, 404);

        return Inertia::render('Inventario/MovimientoDetalle', [
            'movimiento' => [
                'id' => $encontrado->id,
                'tipo' => $encontrado->tipo->value,
                'tipo_etiqueta' => $encontrado->tipo->etiqueta(),
                'direccion' => $encontrado->direccion->value,
                'cantidad' => $encontrado->cantidad,
                'existencia_anterior' => $encontrado->existencia_anterior,
                'existencia_resultante' => $encontrado->existencia_resultante,
                'empresa' => $encontrado->empresa?->nombre_comercial,
                'almacen' => $encontrado->almacen?->nombre,
                'sucursal' => $encontrado->sucursal?->nombre,
                'activo' => $encontrado->activo?->nombre,
                'talla' => $encontrado->talla?->valor,
                'unidad_codigo' => $encontrado->unidadActivo?->codigo,
                'motivo' => $encontrado->motivo,
                'notas' => $encontrado->notas,
                'realizado_por' => $encontrado->realizadoPor?->name,
                'ocurrido_en' => $encontrado->ocurrido_en->toIso8601String(),
                'referencia' => $this->referenciaDetalle($encontrado, $request->user()),
                'colaborador' => $this->colaboradorDelMovimiento($encontrado),
            ],
        ]);
    }

    /**
     * Etiqueta + link (sólo si el usuario puede ver ese recurso concreto) del
     * documento que originó el movimiento. Nunca expone un enlace a algo que
     * el usuario no está autorizado a abrir — en ese caso se muestra sólo la
     * etiqueta, sin `url`.
     *
     * @return array{etiqueta: string|null, url: string|null}
     */
    private function referenciaDetalle(MovimientoInventario $m, User $usuario): array
    {
        if ($m->referencia_tipo === TraspasoInventario::class) {
            $traspaso = TraspasoInventario::query()->find($m->referencia_id);

            return $traspaso === null ? ['etiqueta' => null, 'url' => null] : [
                'etiqueta' => "Traspaso {$traspaso->folio}",
                'url' => $usuario->can('view', $traspaso) ? route('inventario.traspasos.show', $traspaso) : null,
            ];
        }

        if ($m->referencia_tipo === EntregaUniforme::class) {
            $entrega = EntregaUniforme::query()->find($m->referencia_id);

            return $entrega === null ? ['etiqueta' => null, 'url' => null] : [
                'etiqueta' => "Entrega {$entrega->folio}",
                'url' => $usuario->can('view', $entrega) ? route('entregas.show', $entrega) : null,
            ];
        }

        if ($m->referencia_tipo === Devolucion::class) {
            $devolucion = Devolucion::query()->find($m->referencia_id);

            return $devolucion === null ? ['etiqueta' => null, 'url' => null] : [
                'etiqueta' => "Devolución {$devolucion->folio}",
                'url' => $usuario->can('view', $devolucion) ? route('devoluciones.show', $devolucion) : null,
            ];
        }

        return [
            'etiqueta' => match ($m->referencia_tipo) {
                'alta_unidad' => 'Alta de unidad',
                'entrada_manual' => 'Entrada manual',
                'baja_unidad' => 'Baja de unidad',
                default => null,
            },
            'url' => null,
        ];
    }

    /**
     * Colaborador relacionado con el movimiento, derivado SIEMPRE de la
     * entrega/devolución de origen (nunca de una columna propia del
     * movimiento, que no existe).
     */
    private function colaboradorDelMovimiento(MovimientoInventario $m): ?string
    {
        if ($m->referencia_tipo === EntregaUniforme::class) {
            return EntregaUniforme::query()->find($m->referencia_id)?->colaborador?->nombre_completo;
        }

        if ($m->referencia_tipo === Devolucion::class) {
            return Devolucion::query()->find($m->referencia_id)?->colaborador?->nombre_completo;
        }

        return null;
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

    public function almacenarTraspaso(RegistrarTraspasoRequest $request, RegistrarTraspasoFirmado $accion): RedirectResponse
    {
        $datos = $request->validated();

        // Idempotencia: un doble submit o un reintento de red no debe
        // registrar dos traspasos. La clave la genera el formulario (una por
        // intento) — mismo patrón que Entregas.
        $clave = $datos['idempotency_key'] ?? null;
        if ($clave !== null && ! Cache::add("traspasos:idempotencia:{$clave}", true, now()->addMinutes(10))) {
            throw new ExcepcionDeNegocioSimple('Este traspaso ya se registró o se está procesando. Revisa el historial de movimientos.');
        }

        try {
            $acuse = $accion->ejecutar(
                (int) $datos['empresa_origen_id'],
                (int) $datos['almacen_origen_id'],
                (int) $datos['empresa_destino_id'],
                (int) $datos['almacen_destino_id'],
                $datos['renglones'],
                $request->user(),
                $datos['firma'],
                $datos['motivo'] ?? null,
                $datos['notas'] ?? null,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (Throwable $e) {
            // Falló: se libera la clave para permitir un reintento legítimo.
            if ($clave !== null) {
                Cache::forget("traspasos:idempotencia:{$clave}");
            }

            throw $e;
        }

        return to_route('inventario.traspasos.show', $acuse->traspaso_inventario_id)->with('toast', [
            'type' => 'success',
            'message' => "Traspaso {$acuse->traspaso->folio} registrado y firmado correctamente.",
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
            'renglones.unidadActivo:id,codigo',
            'renglones.unidadActivo.especificacion',
            'acuse',
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
                    'unidad_marca_modelo' => $r->unidadActivo?->especificacion?->marcaModelo(),
                    'unidad_imei_mascara' => $r->unidadActivo?->especificacion?->imeiMascara(),
                    'movimiento_salida_id' => $r->movimiento_salida_id,
                    'movimiento_entrada_id' => $r->movimiento_entrada_id,
                ]),
            ],
            'acuse' => $traspaso->acuse === null ? null : [
                'id' => $traspaso->acuse->id,
                'firmante' => $traspaso->acuse->nombre_firmante_snapshot,
                'firmado_en' => $traspaso->acuse->firmado_en->toIso8601String(),
                'tiene_pdf' => $traspaso->acuse->tienePdf(),
                'ver_pdf' => $request->user()->can('verPdf', $traspaso->acuse),
                'ver_firma' => $request->user()->can('verFirma', $traspaso->acuse),
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
            FechaHora::local($m->ocurrido_en),
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
            ->with(['empresa:id,nombre_comercial', 'almacen:id,nombre', 'sucursal:id,nombre', 'activo:id,nombre', 'talla:id,valor', 'unidadActivo:id,codigo', 'realizadoPor:id,name'])
            ->latest('ocurrido_en');
    }

    /**
     * Folios de los traspasos referenciados en una página de movimientos, en
     * UNA consulta (evita N+1 al pintar la referencia en las cards).
     *
     * @param  Collection<int, MovimientoInventario>  $movimientos
     * @return array<int, string>
     */
    private function foliosTraspaso(Collection $movimientos): array
    {
        $ids = $movimientos
            ->where('referencia_tipo', TraspasoInventario::class)
            ->pluck('referencia_id')
            ->filter()
            ->unique()
            ->all();

        if ($ids === []) {
            return [];
        }

        return TraspasoInventario::query()->whereIn('id', $ids)->pluck('folio', 'id')->all();
    }

    /**
     * Etiqueta legible de la referencia de un movimiento para las cards.
     *
     * @param  array<int, string>  $foliosTraspaso
     */
    private function referenciaLegible(MovimientoInventario $m, array $foliosTraspaso): ?string
    {
        if ($m->referencia_tipo === TraspasoInventario::class) {
            $folio = $foliosTraspaso[$m->referencia_id] ?? null;

            return $folio !== null ? "Traspaso {$folio}" : null;
        }

        return match ($m->referencia_tipo) {
            'alta_unidad' => 'Alta de unidad',
            default => null,
        };
    }
}
