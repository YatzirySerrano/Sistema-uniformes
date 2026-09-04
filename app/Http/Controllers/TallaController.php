<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConEmpresa;
use App\Models\DetalleEntrega;
use App\Models\Empresa;
use App\Models\SaldoInventario;
use App\Models\Talla;
use App\Servicios\ServicioAuditoria;
use App\Soporte\AccesoEmpresa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administración del catálogo COMPARTIDO de variantes / tallas. La variante no
 * pertenece a una empresa: se **habilita por empresa** vía `talla_empresa`. El
 * usuario NO captura `orden` (se coloca al final; se cambia con `reordenar`,
 * que es un orden global de plataforma). El "sin variante" ya no es una fila:
 * es `talla_id = NULL` en el inventario.
 */
class TallaController extends Controller
{
    use ConEmpresa;

    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('tallas.administrar') || $request->user()->can('activos.ver'), 403);

        $empresa = $this->empresaDelFiltro($request) ?? $this->empresasAutorizadas($request)->first();
        abort_if($empresa === null, 403, 'No tienes ninguna empresa asignada.');

        $tallas = Talla::query()
            ->with(['empresas:id,nombre_comercial'])
            ->withCount('activos')
            ->ordenadas()
            ->get()
            ->map(fn (Talla $t): array => [
                'id' => $t->id,
                'valor' => $t->valor,
                'activa' => $t->activa,
                'activos_count' => (int) $t->activos_count,
                'habilitada' => $t->empresas->contains('id', $empresa->id),
                'empresas' => $t->empresas->map(fn (Empresa $e): array => ['id' => $e->id, 'nombre_comercial' => $e->nombre_comercial])->all(),
            ]);

        return Inertia::render('Activos/Tallas', [
            'tallas' => $tallas,
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
            'empresaSeleccionadaId' => $empresa->id,
            'puedeAdministrar' => $request->user()->can('tallas.administrar'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('tallas.administrar'), 403);

        $idsAutorizadas = $this->idsEmpresasAutorizadas($request);
        $datos = $request->validate([
            'valor' => ['required', 'string', 'max:30'],
            'empresa_ids' => ['required', 'array', 'min:1'],
            'empresa_ids.*' => ['integer', Rule::in($idsAutorizadas->all())],
        ], [
            'valor.required' => 'Escribe el nombre de la variante / talla.',
            'empresa_ids.required' => 'Elige al menos una empresa para la que habilitar la variante.',
        ]);

        $this->crear($datos['valor'], array_map('intval', $datos['empresa_ids']));

        return back()->with('toast', ['type' => 'success', 'message' => 'Variante / talla agregada.']);
    }

    /**
     * Alta rápida desde el formulario de Activo: crea la variante y la habilita
     * SÓLO para la empresa del formulario.
     */
    public function rapido(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('tallas.administrar'), 403);

        $empresa = $this->resolverEmpresa($request);
        $datos = $request->validate([
            'valor' => ['required', 'string', 'max:30'],
        ], ['valor.required' => 'Escribe el nombre de la variante / talla.']);

        $talla = $this->crear($datos['valor'], [$empresa->id]);

        return response()->json(['talla' => ['id' => $talla->id, 'valor' => $talla->valor]]);
    }

    public function update(Request $request, Talla $talla): RedirectResponse
    {
        $this->autorizarSobre($request, $talla);

        $datos = $request->validate([
            'valor' => ['required', 'string', 'max:30'],
        ], ['valor.required' => 'Escribe el nombre de la variante / talla.']);

        if (Talla::existeNombre($datos['valor'], $talla->id)) {
            throw ValidationException::withMessages(['valor' => 'Esa variante / talla ya existe.']);
        }

        $talla->update([
            'valor' => $datos['valor'],
            'activa' => $request->boolean('activa', $talla->activa),
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Variante / talla actualizada.']);
    }

    /**
     * Habilita / deshabilita la variante para una empresa concreta (toggle desde
     * el listado, columna de la empresa seleccionada). El mensaje nombra la
     * empresa afectada explícitamente.
     */
    public function empresa(Request $request, Talla $talla): RedirectResponse
    {
        abort_unless($request->user()->can('tallas.administrar'), 403);

        $datos = $request->validate(['empresa_id' => ['required', 'integer']]);
        $empresa = Empresa::query()->findOrFail((int) $datos['empresa_id']);
        abort_unless($request->user()->puedeAccederEmpresa($empresa), 403);

        $estaba = $talla->empresas()->whereKey($empresa->id)->exists();
        $estaba ? $talla->empresas()->detach($empresa->id) : $talla->empresas()->attach($empresa->id);

        $mensaje = $estaba
            ? "Variante «{$talla->valor}» deshabilitada para «{$empresa->nombre_comercial}». Sigue disponible globalmente y en las demás empresas donde esté habilitada."
            : "Variante «{$talla->valor}» habilitada para «{$empresa->nombre_comercial}».";

        $this->auditoria->registrar('activos', 'talla_empresas', [
            'tipo_entidad' => Talla::class, 'entidad_id' => $talla->id, 'empresa_id' => $empresa->id,
            'descripcion' => ($estaba ? 'Deshabilitación' : 'Habilitación')." de la variante {$talla->valor} para {$empresa->nombre_comercial}",
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => $mensaje]);
    }

    /**
     * Reordena el catálogo de variantes (orden global de plataforma).
     */
    public function reordenar(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('tallas.administrar'), 403);

        $datos = $request->validate([
            'orden' => ['required', 'array', 'min:1'],
            'orden.*' => ['integer'],
        ]);

        $ids = Talla::query()->whereIn('id', $datos['orden'])->pluck('id')->all();

        DB::transaction(function () use ($datos, $ids): void {
            $posicion = 1;
            foreach ($datos['orden'] as $id) {
                if (! in_array((int) $id, $ids, true)) {
                    continue;
                }
                Talla::query()->whereKey($id)->update(['orden' => $posicion++]);
            }
        });

        $this->auditoria->registrar('activos', 'talla_reordenar', [
            'empresa_id' => $this->empresasAutorizadas($request)->first()?->id,
            'descripcion' => 'Reordenamiento del catálogo de variantes / tallas',
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Orden actualizado.']);
    }

    public function destroy(Request $request, Talla $talla): RedirectResponse
    {
        $this->autorizarSobre($request, $talla);

        $enUso = $talla->activos()->exists()
            || SaldoInventario::query()->where('talla_id', $talla->id)->where('cantidad', '>', 0)->exists()
            || DetalleEntrega::query()->where('talla_id', $talla->id)->exists();

        if ($enUso) {
            return back()->with('toast', ['type' => 'error', 'message' => 'No se puede eliminar la variante porque está en uso. Puedes desactivarla.']);
        }

        $talla->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'Variante / talla eliminada.']);
    }

    /**
     * Búsqueda con autocompletado de variantes (filtros de inventario, etc.).
     */
    public function buscar(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('activos.ver'), 403);

        $termino = trim((string) $request->query('q', ''));
        $consulta = Talla::query()->where('activa', true);

        if ($request->filled('empresa_id')) {
            $empresa = $this->empresaDelFiltro($request);
            if ($empresa === null) {
                return response()->json(['tallas' => []]);
            }
            $consulta->paraEmpresa($empresa->id);
        } else {
            $consulta->whereHas('empresas', fn (Builder $q) => $q->whereIn('empresas.id', $this->idsEmpresasAutorizadas($request)));
        }

        $tallas = $consulta
            ->when($termino !== '', fn (Builder $q) => $q->where('valor', 'like', "%{$termino}%"))
            ->ordenadas()
            ->limit(30)
            ->get(['id', 'valor'])
            ->map(fn (Talla $t): array => ['id' => $t->id, 'valor' => $t->valor]);

        return response()->json(['tallas' => $tallas]);
    }

    private function autorizarSobre(Request $request, Talla $talla): void
    {
        abort_unless($request->user()->can('tallas.administrar'), 403);

        if ($request->user()->tieneAlcanceGlobal()) {
            return;
        }

        $accesibles = app(AccesoEmpresa::class)->idsAutorizados($request->user());
        abort_unless($talla->empresas()->whereIn('empresas.id', $accesibles)->exists(), 403);
    }

    /**
     * @param  array<int, int>  $empresaIds
     */
    private function crear(string $valor, array $empresaIds): Talla
    {
        if (Talla::existeNombre($valor)) {
            throw ValidationException::withMessages(['valor' => 'Esa variante / talla ya existe.']);
        }

        $talla = Talla::query()->create([
            'valor' => $valor,
            'orden' => (int) Talla::query()->max('orden') + 1,
            'activa' => true,
        ]);

        $talla->empresas()->sync(array_values(array_unique($empresaIds)));

        foreach ($empresaIds as $empresaId) {
            $this->auditoria->registrar('activos', 'talla_crear', [
                'tipo_entidad' => Talla::class, 'entidad_id' => $talla->id, 'empresa_id' => $empresaId,
                'descripcion' => 'Alta de variante / talla '.$talla->valor,
            ]);
        }

        return $talla;
    }
}
