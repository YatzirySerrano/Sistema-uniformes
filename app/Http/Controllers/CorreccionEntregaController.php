<?php

namespace App\Http\Controllers;

use App\Acciones\CorregirEntrega;
use App\Http\Controllers\Concerns\ConEmpresaActiva;
use App\Models\EntregaUniforme;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CorreccionEntregaController extends Controller
{
    use ConEmpresaActiva;

    public function create(EntregaUniforme $entrega): Response
    {
        $this->authorize('corregir', $entrega);
        abort_unless($entrega->empresa_id === $this->empresaActiva()->id, 404);

        $entrega->load(['detalles', 'colaborador:id,nombre_completo,numero_empleado']);
        $empresa = $this->empresaActiva();

        return Inertia::render('Entregas/Corregir', [
            'entrega' => [
                'id' => $entrega->id,
                'folio' => $entrega->folio,
                'estado_etiqueta' => $entrega->estado->etiqueta(),
                'colaborador' => $entrega->colaborador?->only(['nombre_completo', 'numero_empleado']),
                'items' => $entrega->detalles->map(fn ($d): array => [
                    'prenda_id' => $d->prenda_id,
                    'talla_id' => $d->talla_id,
                    'prenda' => $d->prenda_nombre_snapshot,
                    'talla' => $d->talla_valor_snapshot,
                    'cantidad' => $d->cantidad,
                ]),
            ],
            'prendas' => $empresa->prendas()->activas()->with('tallas:id,valor')->orderBy('nombre')->get()
                ->map(fn ($p): array => ['id' => $p->id, 'nombre' => $p->nombre, 'tallas' => $p->tallas->map->only(['id', 'valor'])->values()]),
        ]);
    }

    public function store(Request $request, EntregaUniforme $entrega, CorregirEntrega $accion): RedirectResponse
    {
        $this->authorize('corregir', $entrega);
        abort_unless($entrega->empresa_id === $this->empresaActiva()->id, 404);

        $datos = $request->validate([
            'motivo' => ['required', 'string', 'min:5', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.prenda_id' => ['required', 'integer'],
            'items.*.talla_id' => ['required', 'integer'],
            'items.*.cantidad' => ['required', 'integer', 'min:1', 'max:1000'],
        ], ['motivo.required' => 'El motivo de la corrección es obligatorio.']);

        $accion->ejecutar($entrega, $datos['items'], $datos['motivo'], $request->user()->id);

        return to_route('entregas.show', $entrega)->with('toast', [
            'type' => 'success', 'message' => 'Corrección aplicada y registrada en auditoría.',
        ]);
    }
}
