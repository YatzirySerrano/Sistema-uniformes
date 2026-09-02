<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConEmpresaActiva;
use App\Models\DetalleEntrega;
use App\Models\SaldoInventario;
use App\Models\Talla;
use App\Servicios\ServicioAuditoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TallaController extends Controller
{
    use ConEmpresaActiva;

    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    public function index(): Response
    {
        abort_unless(request()->user()->can('tallas.administrar') || request()->user()->can('activos.ver'), 403);

        return Inertia::render('Activos/Tallas', [
            'tallas' => $this->empresaActiva()->tallas()->ordenadas()->get(['id', 'valor', 'orden', 'activa']),
            'puedeAdministrar' => request()->user()->can('tallas.administrar'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('tallas.administrar'), 403);
        $empresaId = $this->empresaActiva()->id;

        $datos = $request->validate([
            'valor' => ['required', 'string', 'max:30', Rule::unique('tallas', 'valor')->where(fn ($q) => $q->where('empresa_id', $empresaId))],
            'orden' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ], ['valor.unique' => 'Esa talla ya existe en la empresa.']);

        $talla = Talla::query()->create([
            'empresa_id' => $empresaId,
            'valor' => $datos['valor'],
            'orden' => $datos['orden'] ?? 0,
            'activa' => true,
        ]);

        $this->auditoria->registrar('activos', 'talla_crear', [
            'tipo_entidad' => Talla::class, 'entidad_id' => $talla->id,
            'descripcion' => 'Alta de talla '.$talla->valor,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Talla agregada.']);
    }

    public function update(Request $request, Talla $talla): RedirectResponse
    {
        abort_unless($request->user()->can('tallas.administrar'), 403);
        $this->verificarEmpresa($talla);
        $empresaId = $this->empresaActiva()->id;

        $datos = $request->validate([
            'valor' => ['required', 'string', 'max:30', Rule::unique('tallas', 'valor')->where(fn ($q) => $q->where('empresa_id', $empresaId))->ignore($talla->id)],
            'orden' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'activa' => ['boolean'],
        ], ['valor.unique' => 'Esa talla ya existe en la empresa.']);

        $talla->update([
            'valor' => $datos['valor'],
            'orden' => $datos['orden'] ?? $talla->orden,
            'activa' => $request->boolean('activa', $talla->activa),
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Talla actualizada.']);
    }

    public function destroy(Talla $talla): RedirectResponse
    {
        abort_unless(request()->user()->can('tallas.administrar'), 403);
        $this->verificarEmpresa($talla);

        $enUso = $talla->activos()->exists()
            || SaldoInventario::query()->where('talla_id', $talla->id)->where('cantidad', '>', 0)->exists()
            || DetalleEntrega::query()->where('talla_id', $talla->id)->exists();

        if ($enUso) {
            return back()->with('toast', ['type' => 'error', 'message' => 'No se puede eliminar la talla porque está en uso. Puedes desactivarla.']);
        }

        $talla->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'Talla eliminada.']);
    }

    private function verificarEmpresa(Talla $talla): void
    {
        abort_unless($talla->empresa_id === $this->empresaActiva()->id, 404);
    }
}
