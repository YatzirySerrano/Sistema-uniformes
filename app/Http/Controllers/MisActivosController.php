<?php

namespace App\Http\Controllers;

use App\Enums\EstadoUnidadActivo;
use App\Enums\FinalidadCustodia;
use App\Models\UnidadActivo;
use App\Servicios\ServicioCustodiaColaborador;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Mis activos": lo que el colaborador VINCULADO a la cuenta tiene hoy bajo
 * custodia, separado en uso personal / para redistribuir / sin clasificar. Vista ligera y de
 * sólo lectura para quien tiene `activos.ver-custodia-propia` sin necesitar
 * el módulo Activos completo (`activos.ver`). El backend sólo consulta la
 * custodia de ESE colaborador: nunca inventario global, almacenes, otros
 * custodios ni catálogo. El detalle de un activo/unidad sigue protegido por
 * sus propias Policies (`activos.ver` / `unidades-activo.ver`).
 */
class MisActivosController extends Controller
{
    public function index(Request $request, ServicioCustodiaColaborador $custodia): Response
    {
        $usuario = $request->user();
        abort_unless($usuario->can('activos.ver-custodia-propia') || $usuario->can('activos.ver'), 403);

        $colaborador = $custodia->colaboradorVinculado($usuario);

        if ($colaborador === null) {
            return Inertia::render('Activos/MisActivos', [
                'colaborador' => null,
                'personales' => [],
                'redistribuir' => [],
                'sinClasificar' => [],
                'conjuntos' => [],
            ]);
        }

        $colaborador->loadMissing(['servicioActual.contrato', 'empresa:id,nombre_comercial', 'sucursal:id,nombre']);

        // Cantidades: una fila por activo + variante + finalidad (sumando
        // renglones de distintas entregas).
        $cantidades = [];
        foreach ($custodia->pendientes($colaborador, [$colaborador->empresa_id]) as $fila) {
            if ($fila['tipo'] !== 'cantidad') {
                continue;
            }

            $clave = $fila['activo'].'|'.($fila['talla'] ?? '').'|'.($fila['finalidad'] ?? '');
            $cantidades[$clave] ??= [
                'tipo' => 'cantidad',
                'activo' => $fila['activo'],
                'talla' => $fila['talla'],
                'cantidad' => 0,
                'codigo' => null,
                'marca_modelo' => null,
                'condicion' => null,
                'finalidad' => $fila['finalidad'],
                'finalidad_etiqueta' => $fila['finalidad_etiqueta'],
            ];
            $cantidades[$clave]['cantidad'] += $fila['cantidad'];
        }

        $unidades = UnidadActivo::query()
            ->where('colaborador_id', $colaborador->getKey())
            ->where('estado', EstadoUnidadActivo::Asignada)
            ->where('empresa_id', $colaborador->empresa_id)
            ->with(['activo:id,nombre', 'especificacion'])
            ->orderBy('codigo')
            ->get()
            ->map(function (UnidadActivo $u) use ($custodia): array {
                $finalidad = $custodia->entregaActualDeUnidad($u)?->finalidad;

                return [
                    'tipo' => 'unidad',
                    'activo' => $u->activo?->nombre,
                    'talla' => null,
                    'cantidad' => 1,
                    'codigo' => $u->codigo,
                    'marca_modelo' => $u->especificacion?->marcaModelo(),
                    'condicion' => $u->condicion->etiqueta(),
                    'finalidad' => $finalidad?->value,
                    'finalidad_etiqueta' => FinalidadCustodia::etiquetaDe($finalidad),
                ];
            });

        $todo = collect(array_values($cantidades))->concat($unidades);
        $conFinalidad = fn (?FinalidadCustodia $finalidad): Closure => fn (array $f): bool => $f['finalidad'] === $finalidad?->value;

        return Inertia::render('Activos/MisActivos', [
            'colaborador' => [
                'nombre_completo' => $colaborador->nombre_completo,
                'numero_empleado' => $colaborador->numero_empleado,
                'empresa' => $colaborador->empresa?->nombre_comercial,
                'sucursal' => $colaborador->sucursal?->nombre,
                // Ubicación operativa: se deriva del servicio vigente.
                'servicio' => $colaborador->servicioActual === null ? null
                    : $colaborador->servicioActual->contrato->nombre.' — '.$colaborador->servicioActual->nombre,
            ],
            // Tres grupos SEPARADOS, tal como están guardados: lo histórico
            // sin finalidad nunca se muestra mezclado con "Uso personal"
            // (aunque, por prudencia, tampoco se ofrece para redistribuir).
            'personales' => $todo->filter($conFinalidad(FinalidadCustodia::UsoPersonal))->values(),
            'redistribuir' => $todo->filter($conFinalidad(FinalidadCustodia::Redistribucion))->values(),
            'sinClasificar' => $todo->filter($conFinalidad(null))->values(),
            'conjuntos' => $custodia->conjuntosRedistribuibles($colaborador),
        ]);
    }
}
