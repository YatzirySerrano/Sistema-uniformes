<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConEmpresa;
use App\Models\DetalleEntrega;
use App\Models\Empresa;
use App\Models\SaldoInventario;
use App\Models\Talla;
use App\Servicios\ServicioAuditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administración de variantes / tallas de una empresa. El usuario NO captura el
 * campo técnico `orden`: al crear una variante se coloca al final
 * (`max(orden) + 1`) y el orden se cambia con `reordenar`. La talla comodín
 * ("sin variante") no se muestra ni se administra aquí. La empresa llega en
 * `empresa_id` y se valida el acceso del usuario.
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

        return Inertia::render('Activos/Tallas', [
            'tallas' => $empresa->tallas()->seleccionables()->ordenadas()->get(['id', 'valor', 'activa']),
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
            'empresaSeleccionadaId' => $empresa->id,
            'puedeAdministrar' => $request->user()->can('tallas.administrar'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('tallas.administrar'), 403);

        $empresa = $this->resolverEmpresa($request);
        $datos = $this->validar($request, $empresa->id);
        $talla = $this->crear($empresa, $datos['valor']);

        $this->auditoria->registrar('activos', 'talla_crear', [
            'tipo_entidad' => Talla::class, 'entidad_id' => $talla->id, 'empresa_id' => $empresa->id,
            'descripcion' => 'Alta de variante / talla '.$talla->valor,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Variante / talla agregada.']);
    }

    /**
     * Alta rápida desde el formulario de Activo. Devuelve la variante creada.
     */
    public function rapido(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('tallas.administrar'), 403);

        $empresa = $this->resolverEmpresa($request);
        $datos = $this->validar($request, $empresa->id);
        $talla = $this->crear($empresa, $datos['valor']);

        $this->auditoria->registrar('activos', 'talla_crear', [
            'tipo_entidad' => Talla::class, 'entidad_id' => $talla->id, 'empresa_id' => $empresa->id,
            'descripcion' => 'Alta de variante / talla '.$talla->valor,
        ]);

        return response()->json(['talla' => ['id' => $talla->id, 'valor' => $talla->valor]]);
    }

    public function update(Request $request, Talla $talla): RedirectResponse
    {
        abort_unless($request->user()->can('tallas.administrar'), 403);
        abort_unless($request->user()->puedeAccederEmpresa($talla->empresa_id), 403);
        abort_if($talla->es_comodin, 403, 'La variante "sin variante" no se puede editar.');

        $datos = $this->validar($request, $talla->empresa_id, $talla->id);

        $talla->update([
            'valor' => $datos['valor'],
            'activa' => $request->boolean('activa', $talla->activa),
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Variante / talla actualizada.']);
    }

    /**
     * Reordena las variantes de la empresa según la lista de IDs recibida.
     */
    public function reordenar(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('tallas.administrar'), 403);
        $empresa = $this->resolverEmpresa($request);

        $datos = $request->validate([
            'orden' => ['required', 'array', 'min:1'],
            'orden.*' => ['integer'],
        ]);

        $ids = Talla::query()
            ->where('empresa_id', $empresa->id)
            ->seleccionables()
            ->whereIn('id', $datos['orden'])
            ->pluck('id')
            ->all();

        DB::transaction(function () use ($datos, $ids, $empresa): void {
            $posicion = 1;
            foreach ($datos['orden'] as $id) {
                if (! in_array((int) $id, $ids, true)) {
                    continue;
                }
                Talla::query()->where('empresa_id', $empresa->id)->whereKey($id)->update(['orden' => $posicion++]);
            }
        });

        $this->auditoria->registrar('activos', 'talla_reordenar', [
            'empresa_id' => $empresa->id,
            'descripcion' => 'Reordenamiento de variantes / tallas',
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Orden actualizado.']);
    }

    public function destroy(Request $request, Talla $talla): RedirectResponse
    {
        abort_unless($request->user()->can('tallas.administrar'), 403);
        abort_unless($request->user()->puedeAccederEmpresa($talla->empresa_id), 403);
        abort_if($talla->es_comodin, 403, 'La variante "sin variante" no se puede eliminar.');

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
     * @return array{valor: string}
     */
    private function validar(Request $request, int $empresaId, ?int $ignorarId = null): array
    {
        return $request->validate([
            'valor' => [
                'required', 'string', 'max:30',
                Rule::unique('tallas', 'valor')
                    ->where(fn ($q) => $q->where('empresa_id', $empresaId))
                    ->ignore($ignorarId),
            ],
        ], [
            'valor.required' => 'Escribe el nombre de la variante / talla.',
            'valor.unique' => 'Esa variante / talla ya existe en la empresa.',
        ]);
    }

    private function crear(Empresa $empresa, string $valor): Talla
    {
        $siguiente = (int) Talla::query()
            ->where('empresa_id', $empresa->id)
            ->seleccionables()
            ->max('orden') + 1;

        return Talla::query()->create([
            'empresa_id' => $empresa->id,
            'valor' => $valor,
            'orden' => $siguiente,
            'activa' => true,
        ]);
    }
}
