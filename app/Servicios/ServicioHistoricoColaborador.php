<?php

namespace App\Servicios;

use App\Models\BitacoraAuditoria;
use App\Models\Colaborador;
use App\Models\Devolucion;
use App\Models\Empresa;
use App\Models\EntregaUniforme;
use App\Models\TransferenciaColaborador;
use Illuminate\Support\Collection;

/**
 * Construye el histórico laboral del colaborador (periodos por empresa) SOLO
 * a partir de datos reales: las filas estructuradas de
 * `transferencias_colaborador` (con IDs, desde que existe esta función) y,
 * como respaldo para el tramo anterior, los eventos `cambiar_empresa` de
 * `bitacora_auditoria` (sólo nombres, nunca se inventan IDs ni fechas). Se
 * apoya en `App\Http\Controllers\ColaboradorController::historico()`, que ya
 * revalida el permiso `verHistorico` (sólo alcance global) antes de llamar.
 */
class ServicioHistoricoColaborador
{
    /**
     * @return list<array<string, mixed>>
     */
    public function construir(Colaborador $colaborador): array
    {
        $colaborador->loadMissing(['sucursal:id,nombre', 'departamento:id,nombre', 'empresa:id,nombre_comercial']);

        $transferencias = TransferenciaColaborador::query()
            ->where('colaborador_id', $colaborador->id)
            ->with([
                'empresaOrigen:id,nombre_comercial',
                'empresaDestino:id,nombre_comercial',
                'sucursalOrigen:id,nombre',
                'sucursalDestino:id,nombre',
                'areaOrigen:id,nombre',
                'areaDestino:id,nombre',
                'usuario:id,name',
            ])
            ->orderBy('ocurrido_en')
            ->get();

        $eventosLegado = $this->eventosLegadoCambioEmpresa($colaborador, $transferencias->count());

        $transiciones = $this->fusionarTransiciones($transferencias, $eventosLegado);

        $periodos = $this->construirPeriodos($colaborador, $transiciones);

        // `fecha_inicio`/`fecha_fin` viajan como CarbonInterface hasta aquí
        // para que las consultas de servicios/entregas/devoluciones comparen
        // fechas reales (no strings ISO, que ordenan mal como texto); sólo al
        // final se serializan a ISO-8601 para el payload de Inertia.
        return array_map(fn (array $periodo): array => [
            ...$periodo,
            'servicios' => $periodo['empresa_id'] === null ? [] : $this->serviciosDelPeriodo($colaborador, $periodo),
            'entregas' => $periodo['empresa_id'] === null ? [] : $this->entregasDelPeriodo($colaborador, $periodo),
            'devoluciones' => $periodo['empresa_id'] === null ? [] : $this->devolucionesDelPeriodo($colaborador, $periodo),
            'movimientosInternos' => $this->movimientosInternosDelPeriodo($colaborador, $periodo),
            'fecha_inicio' => $periodo['fecha_inicio']?->toIso8601String(),
            'fecha_fin' => $periodo['fecha_fin']?->toIso8601String(),
        ], $periodos);
    }

    /**
     * Eventos `cambiar_empresa` de bitácora ANTERIORES a que existiera la
     * tabla estructurada. Desde que existe, cada transferencia escribe AMBAS
     * (bitácora + fila estructurada) en la misma operación, así que hay
     * exactamente un evento de bitácora por cada fila estructurada — pero
     * comparar por timestamp es frágil (dos `now()` distintos en la misma
     * petición pueden diferir en microsegundos). En su lugar: se toman los
     * eventos de bitácora más antiguos, excluyendo tantos como filas
     * estructuradas existan (los más recientes SIEMPRE tienen su contraparte
     * estructurada).
     *
     * @return Collection<int, BitacoraAuditoria>
     */
    private function eventosLegadoCambioEmpresa(Colaborador $colaborador, int $totalEstructuradas): Collection
    {
        $todos = BitacoraAuditoria::query()
            ->where('tipo_entidad', Colaborador::class)
            ->where('entidad_id', $colaborador->id)
            ->where('accion', 'cambiar_empresa')
            ->orderBy('created_at')
            ->get();

        $totalLegado = max(0, $todos->count() - $totalEstructuradas);

        return $todos->take($totalLegado)->values();
    }

    /**
     * @param  Collection<int, TransferenciaColaborador>  $transferencias
     * @param  Collection<int, BitacoraAuditoria>  $eventosLegado
     * @return list<array<string, mixed>>
     */
    private function fusionarTransiciones(Collection $transferencias, Collection $eventosLegado): array
    {
        $nombresEmpresa = $eventosLegado
            ->flatMap(fn (BitacoraAuditoria $e) => [$e->valores_nuevos['empresa'] ?? null, $e->valores_anteriores['empresa'] ?? null])
            ->filter()->unique();
        $empresasPorNombre = $nombresEmpresa->isEmpty() ? collect() : Empresa::query()
            ->whereIn('nombre_comercial', $nombresEmpresa)
            ->get()->keyBy('nombre_comercial');

        $deLegado = $eventosLegado->map(function (BitacoraAuditoria $e) use ($empresasPorNombre): array {
            $nombreEmpresa = $e->valores_nuevos['empresa'] ?? null;
            $nombreEmpresaOrigen = $e->valores_anteriores['empresa'] ?? null;

            return [
                'origen' => 'bitacora',
                'ocurrido_en' => $e->created_at,
                'empresa_id' => $nombreEmpresa !== null ? $empresasPorNombre->get($nombreEmpresa)?->id : null,
                'empresa' => $nombreEmpresa ?? '—',
                'sucursal' => $e->valores_nuevos['sucursal'] ?? null,
                'area' => $e->valores_nuevos['area'] ?? null,
                'numero_empleado' => $e->valores_nuevos['numero_empleado'] ?? null,
                'empresa_origen_id' => $nombreEmpresaOrigen !== null ? $empresasPorNombre->get($nombreEmpresaOrigen)?->id : null,
                'empresa_origen' => $nombreEmpresaOrigen,
                'sucursal_origen' => $e->valores_anteriores['sucursal'] ?? null,
                'area_origen' => $e->valores_anteriores['area'] ?? null,
                'numero_empleado_origen' => $e->valores_anteriores['numero_empleado'] ?? null,
            ];
        });

        $deEstructurado = $transferencias->map(fn (TransferenciaColaborador $t): array => [
            'origen' => 'estructurado',
            'ocurrido_en' => $t->ocurrido_en,
            'empresa_id' => $t->empresa_destino_id,
            'empresa' => $t->empresaDestino->nombre_comercial,
            'sucursal' => $t->sucursalDestino?->nombre,
            'area' => $t->areaDestino?->nombre,
            'numero_empleado' => $t->numero_empleado_nuevo,
            'empresa_origen_id' => $t->empresa_origen_id,
            'empresa_origen' => $t->empresaOrigen?->nombre_comercial,
            'sucursal_origen' => $t->sucursalOrigen?->nombre,
            'area_origen' => $t->areaOrigen?->nombre,
            'numero_empleado_origen' => $t->numero_empleado_anterior,
        ]);

        return array_values($deLegado->concat($deEstructurado)->sortBy('ocurrido_en')->all());
    }

    /**
     * @param  list<array<string, mixed>>  $transiciones
     * @return list<array<string, mixed>>
     */
    private function construirPeriodos(Colaborador $colaborador, array $transiciones): array
    {
        if ($transiciones === []) {
            return [[
                'origen' => 'inicial',
                'actual' => true,
                'empresa_id' => $colaborador->empresa_id,
                'empresa' => $colaborador->empresa->nombre_comercial,
                'sucursal' => $colaborador->sucursal?->nombre,
                'area' => $colaborador->departamento?->nombre,
                'numero_empleado' => $colaborador->numero_empleado,
                'fecha_inicio' => $colaborador->created_at,
                'fecha_inicio_es_alta_registro' => true,
                'fecha_fin' => null,
            ]];
        }

        $periodos = [];
        $total = count($transiciones);

        // Periodo inicial: el estado ANTES de la primera transición conocida
        // (estructurada o reconstruida de bitácora) — ninguna transición por
        // sí sola representa el punto de partida, sólo el destino de un
        // cambio; el origen de la primera SÍ lo conocemos.
        $primera = $transiciones[0];
        $periodos[] = [
            'origen' => $primera['origen'],
            'actual' => false,
            'empresa_id' => $primera['empresa_origen_id'],
            'empresa' => $primera['empresa_origen'] ?? '—',
            'sucursal' => $primera['sucursal_origen'],
            'area' => $primera['area_origen'],
            'numero_empleado' => $primera['numero_empleado_origen'],
            'fecha_inicio' => $colaborador->created_at,
            'fecha_inicio_es_alta_registro' => true,
            'fecha_fin' => $primera['ocurrido_en'],
        ];

        foreach ($transiciones as $i => $t) {
            $siguiente = $transiciones[$i + 1] ?? null;
            $esActual = $i === $total - 1;

            $periodos[] = [
                'origen' => $t['origen'],
                'actual' => $esActual,
                'empresa_id' => $t['empresa_id'],
                'empresa' => $t['empresa'],
                'sucursal' => $t['sucursal'],
                'area' => $t['area'],
                'numero_empleado' => $t['numero_empleado'],
                'fecha_inicio' => $t['ocurrido_en'],
                'fecha_inicio_es_alta_registro' => false,
                'fecha_fin' => $esActual ? null : $siguiente['ocurrido_en'],
            ];
        }

        return $periodos;
    }

    /**
     * @param  array<string, mixed>  $periodo
     * @return list<array{servicio: string, contrato: ?string, ocurrido_en: string}>
     */
    private function serviciosDelPeriodo(Colaborador $colaborador, array $periodo): array
    {
        $eventos = BitacoraAuditoria::query()
            ->where('tipo_entidad', Colaborador::class)
            ->where('entidad_id', $colaborador->id)
            ->where('accion', 'cambiar_servicio')
            // El periodo más antiguo no tiene un inicio real conocido (sólo
            // la fecha de alta del registro, que no es la de contratación):
            // no se aplica cota inferior para no ocultar eventos reales.
            ->when(! $periodo['fecha_inicio_es_alta_registro'], fn ($q) => $q->where('created_at', '>=', $periodo['fecha_inicio']))
            ->when($periodo['fecha_fin'] !== null, fn ($q) => $q->where('created_at', '<', $periodo['fecha_fin']))
            ->orderBy('created_at')
            ->limit(50)
            ->get()
            ->map(fn (BitacoraAuditoria $e): array => [
                'servicio' => $e->valores_nuevos['servicio'] ?? 'Sin servicio',
                'contrato' => $e->valores_nuevos['contrato'] ?? null,
                'ocurrido_en' => $e->created_at?->toIso8601String(),
            ]);

        return array_values($eventos->all());
    }

    /**
     * Cambios de SUCURSAL/ÁREA dentro de la MISMA empresa (edición normal del
     * colaborador, `ColaboradorController::update()` — nunca
     * `CambiarEmpresaColaborador`, que ya tiene su propio tramo estructurado
     * vía `transferencias_colaborador`). No crea una tabla nueva: se
     * reconstruye de `bitacora_auditoria` (`accion=editar`), que
     * `ColaboradorController::update()` enriquece con el nombre de sucursal
     * ANTES/DESPUÉS sólo cuando de verdad cambió (`area` ya lo trae siempre,
     * es columna espejo de texto) — mismo criterio de "sólo si hay una
     * diferencia real" que ya usa `DescripcionAuditoria::cambios()`.
     *
     * @param  array<string, mixed>  $periodo
     * @return list<array{sucursal_anterior: ?string, sucursal_nueva: ?string, area_anterior: ?string, area_nueva: ?string, usuario: ?string, ocurrido_en: ?string}>
     */
    private function movimientosInternosDelPeriodo(Colaborador $colaborador, array $periodo): array
    {
        $eventos = BitacoraAuditoria::query()
            ->where('tipo_entidad', Colaborador::class)
            ->where('entidad_id', $colaborador->id)
            ->where('accion', 'editar')
            ->when(! $periodo['fecha_inicio_es_alta_registro'], fn ($q) => $q->where('created_at', '>=', $periodo['fecha_inicio']))
            ->when($periodo['fecha_fin'] !== null, fn ($q) => $q->where('created_at', '<', $periodo['fecha_fin']))
            ->orderBy('created_at')
            ->limit(50)
            ->get()
            ->filter(function (BitacoraAuditoria $e): bool {
                $cambioSucursal = array_key_exists('sucursal', $e->valores_nuevos ?? []);
                $cambioArea = ($e->valores_anteriores['area'] ?? null) !== ($e->valores_nuevos['area'] ?? null);

                return $cambioSucursal || $cambioArea;
            })
            ->map(fn (BitacoraAuditoria $e): array => [
                'sucursal_anterior' => $e->valores_anteriores['sucursal'] ?? null,
                'sucursal_nueva' => $e->valores_nuevos['sucursal'] ?? null,
                'area_anterior' => $e->valores_anteriores['area'] ?? null,
                'area_nueva' => $e->valores_nuevos['area'] ?? null,
                'usuario' => $e->nombre_usuario_snapshot,
                'ocurrido_en' => $e->created_at?->toIso8601String(),
            ]);

        return array_values($eventos->all());
    }

    /**
     * @param  array<string, mixed>  $periodo
     * @return list<array{id: int, folio: string, fecha: string}>
     */
    private function entregasDelPeriodo(Colaborador $colaborador, array $periodo): array
    {
        $entregas = EntregaUniforme::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('empresa_id', $periodo['empresa_id'])
            ->when(! $periodo['fecha_inicio_es_alta_registro'], fn ($q) => $q->whereDate('fecha_entrega', '>=', $periodo['fecha_inicio']))
            ->when($periodo['fecha_fin'] !== null, fn ($q) => $q->whereDate('fecha_entrega', '<', $periodo['fecha_fin']))
            ->orderByDesc('fecha_entrega')
            ->limit(50)
            ->get(['id', 'folio', 'fecha_entrega'])
            ->map(fn (EntregaUniforme $e): array => [
                'id' => $e->id, 'folio' => $e->folio, 'fecha' => $e->fecha_entrega->toDateString(),
            ]);

        return array_values($entregas->all());
    }

    /**
     * @param  array<string, mixed>  $periodo
     * @return list<array{id: int, folio: string, fecha: string}>
     */
    private function devolucionesDelPeriodo(Colaborador $colaborador, array $periodo): array
    {
        $devoluciones = Devolucion::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('empresa_id', $periodo['empresa_id'])
            ->when(! $periodo['fecha_inicio_es_alta_registro'], fn ($q) => $q->whereDate('fecha', '>=', $periodo['fecha_inicio']))
            ->when($periodo['fecha_fin'] !== null, fn ($q) => $q->whereDate('fecha', '<', $periodo['fecha_fin']))
            ->orderByDesc('fecha')
            ->limit(50)
            ->get(['id', 'folio', 'fecha'])
            ->map(fn (Devolucion $d): array => [
                'id' => $d->id, 'folio' => $d->folio, 'fecha' => $d->fecha->toDateString(),
            ]);

        return array_values($devoluciones->all());
    }
}
