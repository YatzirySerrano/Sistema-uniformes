<?php

namespace App\Http\Controllers;

use App\Acciones\CancelarCambioServicio;
use App\Acciones\CompletarCambioServicio;
use App\Acciones\GuardarDecisionesCambioServicio;
use App\Acciones\IniciarCambioServicio;
use App\Enums\CondicionUnidadActivo;
use App\Http\Requests\Colaboradores\CambiarServicioColaboradorRequest;
use App\Http\Requests\Colaboradores\GuardarDecisionesCambioServicioRequest;
use App\Models\CambioServicioColaborador;
use App\Models\CambioServicioRenglon;
use App\Models\Colaborador;
use App\Models\Devolucion;
use App\Models\Servicio;
use App\Servicios\ServicioCambioServicio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cambio de servicio de un colaborador CON custodia: revisión bien por bien
 * (mantener / devolver / redistribuir) antes de aplicar el cambio. Las
 * devoluciones y redistribuciones se registran con sus flujos reales y
 * firmados (Devoluciones, Entregas en modo custodia); esta pantalla sólo
 * guarda el plan y verifica que la realidad lo cumpla.
 */
class CambioServicioController extends Controller
{
    /**
     * Sin custodia aplica el cambio de inmediato; con custodia abre la
     * revisión y lleva a su pantalla.
     */
    public function store(CambiarServicioColaboradorRequest $request, Colaborador $colaborador, IniciarCambioServicio $accion): RedirectResponse
    {
        $datos = $request->validated();

        $cambio = $accion->ejecutar($colaborador, isset($datos['servicio_id']) ? (int) $datos['servicio_id'] : null, $datos['motivo'] ?? null, $request->user()->id);

        if ($cambio === null) {
            return back()->with('toast', ['type' => 'success', 'message' => 'Servicio actualizado correctamente.']);
        }

        return to_route('cambios-servicio.show', $cambio)->with('toast', [
            'type' => 'info',
            'message' => 'El colaborador tiene bienes bajo custodia. Decide qué pasa con cada uno antes de completar el cambio.',
        ]);
    }

    public function show(Request $request, CambioServicioColaborador $cambio, ServicioCambioServicio $servicio): Response
    {
        $this->authorize('view', $cambio);

        $cambio->load([
            'colaborador:id,nombre_completo,numero_empleado,empresa_id,sucursal_id',
            'colaborador.empresa:id,nombre_comercial',
            'colaborador.sucursal:id,nombre',
            'servicioOrigen:id,nombre,contrato_id', 'servicioOrigen.contrato:id,nombre',
            'servicioDestino:id,nombre,contrato_id', 'servicioDestino.contrato:id,nombre',
            'iniciadoPor:id,name', 'completadoPor:id,name',
            'renglones.destinatario:id,nombre_completo,numero_empleado',
            'renglones.unidadActivo:id,condicion',
        ]);

        $usuario = $request->user();
        $evaluacion = $servicio->evaluar($cambio);
        $estadoPorRenglon = collect($evaluacion['renglones'])->keyBy('id');

        return Inertia::render('Colaboradores/CambioServicio', [
            'cambio' => [
                'id' => $cambio->id,
                'estado' => $cambio->estado,
                'motivo' => $cambio->motivo,
                'colaborador' => [
                    'id' => $cambio->colaborador->id,
                    'nombre_completo' => $cambio->colaborador->nombre_completo,
                    'numero_empleado' => $cambio->colaborador->numero_empleado,
                    'empresa_id' => $cambio->colaborador->empresa_id,
                    'empresa' => $cambio->colaborador->empresa?->nombre_comercial,
                    'sucursal' => $cambio->colaborador->sucursal?->nombre,
                ],
                'servicio_origen' => $this->etiquetaServicio($cambio->servicioOrigen),
                'servicio_destino' => $this->etiquetaServicio($cambio->servicioDestino),
                'iniciado_por' => $cambio->iniciadoPor?->name,
                'iniciado_en' => $cambio->created_at?->toIso8601String(),
                'completado_por' => $cambio->completadoPor?->name,
                'completado_en' => $cambio->completado_en?->toIso8601String(),
            ],
            'renglones' => $cambio->renglones->map(fn (CambioServicioRenglon $r): array => [
                'id' => $r->id,
                'es_unidad' => $r->esUnidad(),
                'activo' => $r->activo_nombre_snapshot,
                'talla' => $r->talla_valor_snapshot,
                'codigo' => $r->unidad_codigo_snapshot,
                'cantidad_revisada' => $r->cantidad_revisada,
                // Una unidad perdida/robada/dañada sólo puede mantenerse.
                'solo_mantener' => $r->esUnidad() && $r->unidadActivo?->condicion !== CondicionUnidadActivo::Funcionando,
                'mantener' => $r->cantidad_mantener,
                'devolver' => $r->cantidad_devolver,
                'redistribuir' => $r->cantidad_redistribuir,
                'destinatario' => $r->destinatario?->only(['id', 'nombre_completo', 'numero_empleado']),
                ...$estadoPorRenglon->get($r->id, []),
            ])->values(),
            'nuevos' => $evaluacion['nuevos'],
            'completable' => $evaluacion['completable'],
            'permisos' => [
                'gestionar' => $usuario->can('gestionar', $cambio) && $cambio->estaPendiente(),
                // Sin conceder nada implícito: la pantalla explica qué falta
                // si el usuario no puede registrar la operación requerida.
                'devolver' => $usuario->can('create', Devolucion::class),
                'redistribuir' => $usuario->can('redistribuirCustodia', $cambio),
            ],
        ]);
    }

    public function guardar(GuardarDecisionesCambioServicioRequest $request, CambioServicioColaborador $cambio, GuardarDecisionesCambioServicio $accion): RedirectResponse
    {
        $accion->ejecutar($cambio, $request->validated('renglones'));

        return back()->with('toast', ['type' => 'success', 'message' => 'Decisiones guardadas.']);
    }

    public function actualizar(CambioServicioColaborador $cambio, GuardarDecisionesCambioServicio $accion): RedirectResponse
    {
        $this->authorize('gestionar', $cambio);

        $agregados = $accion->incorporarCustodiaNueva($cambio);

        return back()->with('toast', ['type' => 'success', 'message' => $agregados > 0
            ? "Se agregaron {$agregados} bienes nuevos a la revisión."
            : 'La revisión ya incluye toda la custodia actual.']);
    }

    public function completar(Request $request, CambioServicioColaborador $cambio, CompletarCambioServicio $accion): RedirectResponse
    {
        $this->authorize('gestionar', $cambio);

        $accion->ejecutar($cambio, $request->user()->id);

        return to_route('colaboradores.show', $cambio->colaborador_id)
            ->with('toast', ['type' => 'success', 'message' => 'Cambio de servicio completado.']);
    }

    public function cancelar(Request $request, CambioServicioColaborador $cambio, CancelarCambioServicio $accion): RedirectResponse
    {
        $this->authorize('gestionar', $cambio);

        $accion->ejecutar($cambio, $request->user()->id);

        return to_route('colaboradores.show', $cambio->colaborador_id)
            ->with('toast', ['type' => 'success', 'message' => 'Revisión cancelada. El colaborador conserva su servicio actual.']);
    }

    private function etiquetaServicio(?Servicio $servicio): string
    {
        return $servicio === null ? 'Sin servicio' : $servicio->contrato->nombre.' — '.$servicio->nombre;
    }
}
