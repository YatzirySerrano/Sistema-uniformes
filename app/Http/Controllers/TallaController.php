<?php

namespace App\Http\Controllers;

use App\Models\Activo;
use App\Models\DetalleEntrega;
use App\Models\SaldoInventario;
use App\Models\Talla;
use App\Servicios\ServicioAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administración del catálogo GLOBAL de variantes / tallas. Es de plataforma:
 * visible para todas las empresas por igual, sin habilitación por empresa. El
 * usuario NO captura `orden` (se coloca al final; se cambia con `reordenar`,
 * orden global de plataforma). El "sin variante" ya no es una fila: es
 * `talla_id = NULL` en el inventario.
 */
class TallaController extends Controller
{
    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('tallas.administrar') || $request->user()->can('activos.ver'), 403);

        $tallas = Talla::query()
            ->withCount('activos')
            ->ordenadas()
            ->get()
            ->map(fn (Talla $t): array => [
                'id' => $t->id,
                'valor' => $t->valor,
                'activa' => $t->activa,
                'activos_count' => (int) $t->activos_count,
            ]);

        return Inertia::render('Activos/Tallas', [
            'tallas' => $tallas,
            'puedeAdministrar' => $request->user()->can('tallas.administrar'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('tallas.administrar'), 403);

        $datos = $request->validate([
            'valor' => ['required', 'string', 'max:30'],
        ], [
            'valor.required' => 'Escribe el nombre de la variante / talla.',
        ]);

        $this->crear($datos['valor']);

        return back()->with('toast', ['type' => 'success', 'message' => 'Variante / talla agregada.']);
    }

    /**
     * Alta rápida desde el formulario de Activo: crea la variante en el
     * catálogo global.
     */
    public function rapido(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('tallas.administrar'), 403);

        $datos = $request->validate([
            'valor' => ['required', 'string', 'max:30'],
        ], ['valor.required' => 'Escribe el nombre de la variante / talla.']);

        $talla = $this->crear($datos['valor']);

        return response()->json(['talla' => ['id' => $talla->id, 'valor' => $talla->valor]]);
    }

    public function update(Request $request, Talla $talla): RedirectResponse
    {
        abort_unless($request->user()->can('tallas.administrar'), 403);

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
            'descripcion' => 'Reordenamiento del catálogo de variantes / tallas',
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Orden actualizado.']);
    }

    public function destroy(Request $request, Talla $talla): RedirectResponse
    {
        abort_unless($request->user()->can('tallas.administrar'), 403);

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
     * Catálogo global: no depende de empresa.
     */
    /**
     * Sin `activo_id`: catálogo global de variantes activas (alta/edición de
     * un Activo, donde se eligen cuáles asociar). Con `activo_id`: SÓLO las
     * variantes ya asociadas a ESE activo y activas — `Activo::tallasElegibles()`
     * es la fuente única (misma regla que `activos/buscar` y el alta de
     * entrada), para que "Agregar existencias" nunca ofrezca una talla ajena
     * al activo. `activo_id` fuera del alcance del usuario devuelve vacío
     * (defensa IDOR), nunca el catálogo global como si no se hubiera filtrado.
     */
    public function buscar(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('activos.ver'), 403);

        $termino = trim((string) $request->query('q', ''));
        $activoId = $request->filled('activo_id') ? $request->integer('activo_id') : null;

        if ($activoId !== null) {
            $activo = Activo::query()->find($activoId);

            if ($activo === null || ! $request->user()->puedeAccederEmpresa($activo->empresa_id)) {
                return response()->json(['tallas' => []]);
            }

            $tallas = $activo->tallasElegibles()
                ->when(
                    $termino !== '',
                    fn ($tallas) => $tallas->filter(fn (Talla $t): bool => str_contains(Str::lower($t->valor), Str::lower($termino))),
                )
                ->take(30)
                ->map(fn (Talla $t): array => ['id' => $t->id, 'valor' => $t->valor])
                ->values();

            return response()->json(['tallas' => $tallas]);
        }

        $tallas = Talla::query()
            ->where('activa', true)
            ->when($termino !== '', fn (Builder $q) => $q->where('valor', 'like', "%{$termino}%"))
            ->ordenadas()
            ->limit(30)
            ->get(['id', 'valor'])
            ->map(fn (Talla $t): array => ['id' => $t->id, 'valor' => $t->valor]);

        return response()->json(['tallas' => $tallas]);
    }

    private function crear(string $valor): Talla
    {
        if (Talla::existeNombre($valor)) {
            throw ValidationException::withMessages(['valor' => 'Esa variante / talla ya existe.']);
        }

        $talla = Talla::query()->create([
            'valor' => $valor,
            'orden' => (int) Talla::query()->max('orden') + 1,
            'activa' => true,
        ]);

        $this->auditoria->registrar('activos', 'talla_crear', [
            'tipo_entidad' => Talla::class, 'entidad_id' => $talla->id,
            'descripcion' => 'Alta de variante / talla '.$talla->valor,
        ]);

        return $talla;
    }
}
