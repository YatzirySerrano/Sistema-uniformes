<?php

namespace App\Http\Controllers\Portal;

use App\Enums\EstadoEntrega;
use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\EntregaUniforme;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Portal simplificado para colaboradores con cuenta de acceso. Sólo muestra
 * información propia: sus entregas, pendientes de firma y comprobantes.
 */
class PortalController extends Controller
{
    public function index(Request $request): Response
    {
        $colaboradores = Colaborador::query()
            ->where('usuario_id', $request->user()->id)
            ->pluck('id');

        $registros = EntregaUniforme::query()
            ->whereIn('colaborador_id', $colaboradores)
            ->with(['detalles', 'sucursal:id,nombre', 'empresa:id,nombre_comercial', 'acuse'])
            ->latest('fecha_entrega')
            ->get()
            ->all();

        $entregas = array_map(fn (EntregaUniforme $e): array => [
            'id' => $e->id,
            'folio' => $e->folio,
            'empresa' => $e->empresa?->nombre_comercial,
            'sucursal' => $e->sucursal?->nombre,
            'fecha_entrega' => $e->fecha_entrega->toDateString(),
            'estado' => $e->estado->value,
            'estado_etiqueta' => $e->estado->etiqueta(),
            'pendiente_firma' => $e->estado === EstadoEntrega::PendienteFirma,
            'acuse_id' => $e->acuse?->id,
            'items' => array_map(fn ($d): array => [
                'prenda' => $d->prenda_nombre_snapshot,
                'talla' => $d->talla_valor_snapshot,
                'cantidad' => $d->cantidad,
            ], $e->detalles->all()),
        ], $registros);

        return Inertia::render('Portal/MisEntregas', [
            'entregas' => $entregas,
            'sinRegistro' => $colaboradores->isEmpty(),
        ]);
    }
}
