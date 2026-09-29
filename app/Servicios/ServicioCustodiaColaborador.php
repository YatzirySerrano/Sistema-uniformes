<?php

namespace App\Servicios;

use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoDevolucion;
use App\Enums\EstadoEntrega;
use App\Enums\EstadoUnidadActivo;
use App\Enums\TipoControlActivo;
use App\Models\Activo;
use App\Models\Colaborador;
use App\Models\DetalleDevolucion;
use App\Models\DetalleEntrega;
use App\Models\EntregaUniforme;
use App\Models\IncidenciaCustodia;
use App\Models\UnidadActivo;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Fuente ÚNICA de verdad de la "custodia pendiente": qué activos tiene
 * todavía físicamente en su poder un colaborador (o una entrega concreta) y
 * aún no se ha devuelto por el flujo formal. La usan la previsualización del
 * cambio de empresa, el re-chequeo bajo lock de `CambiarEmpresaColaborador`,
 * el selector de "Entrega de origen" de Devoluciones (`EntregaController::buscar`)
 * y la validación al registrar una devolución (`RegistrarDevolucion`) — un solo
 * algoritmo, nunca duplicado.
 *
 * Regla de negocio:
 * - Artículos por CANTIDAD: por cada renglón de una entrega firmada/corregida,
 *   `pendiente = entregado − devuelto CONFIRMADO − reportado como robo/pérdida
 *   − REDISTRIBUIDO a otro colaborador` (`App\Models\IncidenciaCustodia` y los
 *   renglones hijos con `detalle_origen_id`, ver `pendientesPorDetalle()`).
 *   Lo redistribuido pasa a contar en la custodia del destinatario (su
 *   propio renglón), nunca en las dos a la vez. Una
 *   devolución todavía `pendiente_firma` NO libera custodia ni cuenta como
 *   devuelta (el colaborador sigue teniendo el artículo hasta que ambas
 *   firmas concreten la devolución). Un robo/pérdida reportado tampoco
 *   "libera" en el sentido de que la pieza regrese a algún lado: sólo dejó de
 *   estar pendiente de devolución porque ya no va a devolverse — nunca se
 *   descuenta dos veces la misma pieza entre ambas fuentes.
 * - UNIDADES identificadas: toda unidad con `colaborador_id = X` y
 *   `estado = Asignada` — cubre asignadas normales y también perdidas/robadas
 *   (`MarcarUnidadIncidencia` conserva `estado = Asignada`): una responsabilidad
 *   abierta bloquea el traslado hasta resolverse por su flujo correcto. Una
 *   redistribución cambia `colaborador_id` de la unidad, así que sale de la
 *   custodia del origen y entra a la del destinatario en el mismo instante.
 *
 * La usan también el cambio de servicio (`CambiarServicioColaborador`), la
 * redistribución (`RedistribuirCustodia`) y sus selectores.
 *
 * Nunca crea devoluciones ni mueve inventario: sólo LEE y clasifica.
 */
class ServicioCustodiaColaborador
{
    /**
     * ¿El colaborador tiene algo pendiente de devolución? Versión barata para
     * el gate (no arma la lista completa).
     */
    public function tienePendientes(Colaborador $colaborador): bool
    {
        $tieneUnidad = UnidadActivo::query()
            ->where('colaborador_id', $colaborador->getKey())
            ->where('estado', EstadoUnidadActivo::Asignada)
            ->exists();

        if ($tieneUnidad) {
            return true;
        }

        return $this->cantidadesPendientes($colaborador) !== [];
    }

    /**
     * Resumen humano de la custodia pendiente, agrupado para un mensaje de
     * bloqueo ("Camisa talla M: 7", "Microondas MIC-003"): cantidades
     * sumadas por activo + variante (aunque vengan de varias entregas) y una
     * línea por unidad identificada. Misma fuente que `pendientes()`.
     *
     * @param  list<array{tipo: string, activo: string, talla: string|null, cantidad: int, referencia: string|null}>|null  $pendientes  resultado ya calculado de `pendientes()` (evita repetir la consulta)
     * @return list<string>
     */
    public function resumenLegible(Colaborador $colaborador, ?array $pendientes = null): array
    {
        $cantidades = [];
        $unidades = [];

        foreach ($pendientes ?? $this->pendientes($colaborador) as $fila) {
            if ($fila['tipo'] === 'unidad') {
                $unidades[] = $fila['activo'].' '.$fila['referencia'];

                continue;
            }

            $clave = $fila['activo'].($fila['talla'] !== null ? ' talla '.$fila['talla'] : '');
            $cantidades[$clave] = ($cantidades[$clave] ?? 0) + $fila['cantidad'];
        }

        $lineas = [];
        foreach ($cantidades as $etiqueta => $total) {
            $lineas[] = "{$etiqueta}: {$total}";
        }

        return [...$lineas, ...$unidades];
    }

    /**
     * Registro de colaborador (activo) ligado a la cuenta del usuario dentro
     * de una empresa: es quien POSEE la custodia cuando ese usuario
     * redistribuye. El usuario autenticado no es el custodio — el custodio es
     * su `Colaborador` (`colaboradores.usuario_id`). Sin registro ligado en
     * esa empresa no hay custodia que redistribuir (nunca se inventa una).
     */
    public function custodioDeUsuario(User $usuario, int $empresaId): ?Colaborador
    {
        if (! $usuario->puedeAccederEmpresa($empresaId)) {
            return null;
        }

        return Colaborador::query()
            ->where('usuario_id', $usuario->getKey())
            ->where('empresa_id', $empresaId)
            ->where('activo', true)
            ->orderBy('id')
            ->first();
    }

    /**
     * Custodios ligados al usuario en sus empresas autorizadas y activas
     * (normalmente uno): alimentan el selector de empresa del modo
     * "redistribuir mi custodia".
     *
     * @return Collection<int, Colaborador>
     */
    public function custodiosDeUsuario(User $usuario): Collection
    {
        return Colaborador::query()
            ->where('usuario_id', $usuario->getKey())
            ->where('activo', true)
            ->whereHas('empresa', fn (Builder $q) => $q->where('activa', true))
            ->with('empresa:id,codigo,nombre_comercial')
            ->orderBy('id')
            ->get()
            ->filter(fn (Colaborador $c): bool => $usuario->puedeAccederEmpresa($c->empresa_id))
            ->unique('empresa_id')
            ->values();
    }

    /**
     * Existencias por CANTIDAD que el custodio puede redistribuir AHORA,
     * agregadas por activo + variante (suma de los saldos pendientes de todos
     * sus renglones). Sólo activos por cantidad activos de su empresa — lo
     * mismo que exige la entrega. Lectura sin lock: la autoridad es
     * `RedistribuirCustodia`, que recalcula bajo lock al confirmar.
     *
     * @return list<array{activo_id: int, talla_id: int|null, activo: string, talla: string|null, disponible: int}>
     */
    public function cantidadesRedistribuibles(Colaborador $custodio): array
    {
        $filas = $this->cantidadesPendientes($custodio, [$custodio->empresa_id]);

        if ($filas === []) {
            return [];
        }

        $activosValidos = Activo::query()
            ->whereIn('id', array_unique(array_column($filas, 'activo_id')))
            ->where('empresa_id', $custodio->empresa_id)
            ->where('tipo_control', TipoControlActivo::Cantidad)
            ->where('activo', true)
            ->pluck('nombre', 'id');

        $agrupado = [];
        foreach ($filas as $fila) {
            if (! $activosValidos->has($fila['activo_id'])) {
                continue;
            }

            $clave = $fila['activo_id'].'-'.($fila['talla_id'] ?? '0');
            $agrupado[$clave] ??= [
                'activo_id' => $fila['activo_id'],
                'talla_id' => $fila['talla_id'],
                'activo' => (string) $activosValidos[$fila['activo_id']],
                'talla' => $fila['talla'],
                'disponible' => 0,
            ];
            $agrupado[$clave]['disponible'] += $fila['pendiente'];
        }

        return array_values($agrupado);
    }

    /**
     * Unidades identificadas que el custodio puede redistribuir: asignadas a
     * él, funcionando (una perdida/robada/dañada no se reasigna) y sin una
     * devolución pendiente de firma.
     *
     * @return Builder<UnidadActivo>
     */
    public function unidadesRedistribuibles(Colaborador $custodio): Builder
    {
        return UnidadActivo::query()
            ->where('empresa_id', $custodio->empresa_id)
            ->where('colaborador_id', $custodio->getKey())
            ->where('estado', EstadoUnidadActivo::Asignada)
            ->where('condicion', CondicionUnidadActivo::Funcionando)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('detalles_devolucion as dd')
                ->join('devoluciones as dv', 'dv.id', '=', 'dd.devolucion_id')
                ->whereColumn('dd.unidad_activo_id', 'unidades_activo.id')
                ->where('dv.estado', EstadoDevolucion::PendienteFirma->value));
    }

    /**
     * Detalle legible de la custodia pendiente, para la previsualización del
     * wizard de transferencia y para contextualizar "Nueva devolución" desde
     * ahí: una fila por unidad identificada + una fila por renglón de
     * cantidad con saldo pendiente, agrupable por entrega de origen mediante
     * `entrega_id`/`entrega_folio`. Trae SIEMPRE los IDs reales — nunca sólo
     * el folio — para que el frontend pueda enlazar directo sin que el
     * usuario tenga que memorizar ni volver a buscar nada.
     *
     * @param  array<int, int>|null  $idsEmpresasAutorizadas  Aislamiento histórico (ver `totalPiezasPendientes()`): `null` = sin acotar (uso interno del wizard de transferencia, que ya exige alcance global), un arreglo = sólo lo que esas empresas autorizan (panel de custodia en el perfil del colaborador).
     * @return list<array{tipo: string, tipo_etiqueta: string, activo: string, talla: string|null, cantidad: int, referencia: string|null, entrega_id: int|null, entrega_folio: string|null, detalle_entrega_id: int|null, unidad_activo_id: int|null, unidad_public_token: string|null}>
     */
    public function pendientes(Colaborador $colaborador, ?array $idsEmpresasAutorizadas = null): array
    {
        $unidades = UnidadActivo::query()
            ->where('colaborador_id', $colaborador->getKey())
            ->where('estado', EstadoUnidadActivo::Asignada)
            ->when($idsEmpresasAutorizadas !== null, fn (Builder $q) => $q->whereIn('empresa_id', $idsEmpresasAutorizadas))
            ->with('activo:id,nombre')
            ->orderBy('codigo')
            ->get()
            ->map(function (UnidadActivo $u): array {
                $detalle = $this->entregaActualDeUnidad($u);

                return [
                    'tipo' => 'unidad',
                    'tipo_etiqueta' => 'Unidad identificada',
                    'activo' => $u->activo->nombre,
                    'talla' => null,
                    'cantidad' => 1,
                    'referencia' => $u->codigo,
                    'entrega_id' => $detalle?->entrega_uniforme_id,
                    'entrega_folio' => $detalle?->entrega?->folio,
                    'detalle_entrega_id' => $detalle?->id,
                    'unidad_activo_id' => $u->getKey(),
                    'unidad_public_token' => $u->public_token,
                ];
            })
            ->all();

        $cantidades = array_map(fn (array $fila): array => [
            'tipo' => 'cantidad',
            'tipo_etiqueta' => 'Artículo por cantidad',
            'activo' => $fila['activo'],
            'talla' => $fila['talla'],
            'cantidad' => $fila['pendiente'],
            'referencia' => $fila['folio'],
            'entrega_id' => $fila['entrega_id'],
            'entrega_folio' => $fila['folio'],
            'detalle_entrega_id' => $fila['detalle_entrega_id'],
            'unidad_activo_id' => null,
            'unidad_public_token' => null,
        ], $this->cantidadesPendientes($colaborador, $idsEmpresasAutorizadas));

        return array_merge($unidades, $cantidades);
    }

    /**
     * Robos/pérdidas de artículos por cantidad ya reportados para este
     * colaborador — lista legible para el panel de custodia del perfil.
     * Nunca recalcula nada: son eventos ya persistidos, mismo aislamiento
     * histórico que `pendientes()`.
     *
     * @param  array<int, int>|null  $idsEmpresasAutorizadas
     * @return list<array{activo: string, talla: string|null, cantidad: int, tipo: string, tipo_etiqueta: string, motivo: string, observacion: string|null, entrega_folio: string|null, usuario: string|null, ocurrido_en: string|null}>
     */
    public function incidenciasRegistradas(Colaborador $colaborador, ?array $idsEmpresasAutorizadas = null): array
    {
        $filas = IncidenciaCustodia::query()
            ->where('colaborador_id', $colaborador->getKey())
            ->when($idsEmpresasAutorizadas !== null, fn (Builder $q) => $q->whereIn('empresa_id', $idsEmpresasAutorizadas))
            ->with(['entrega:id,folio', 'registradoPor:id,name'])
            ->latest('id')
            ->get()
            ->map(fn (IncidenciaCustodia $i): array => [
                'activo' => $i->activo_nombre_snapshot,
                'talla' => $i->talla_valor_snapshot,
                'cantidad' => $i->cantidad,
                'tipo' => $i->tipo->value,
                'tipo_etiqueta' => $i->tipo->etiqueta(),
                'motivo' => $i->motivo,
                'observacion' => $i->observacion,
                'entrega_folio' => $i->entrega?->folio,
                'usuario' => $i->registradoPor?->name,
                'ocurrido_en' => $i->created_at?->toIso8601String(),
            ])
            ->all();

        return array_values($filas);
    }

    /**
     * Pendiente real de UN renglón de entrega por cantidad: entregado menos
     * lo devuelto en devoluciones CONFIRMADAS y menos lo reportado como robo/
     * pérdida. Fuente única reutilizada por `RegistrarDevolucion` (validación
     * al registrar), `DevolucionController` (presentación del formulario) y
     * `RegistrarIncidenciaCustodia` (validación al reportar) — nunca vuelvas
     * a sumar `DetalleDevolucion`/`IncidenciaCustodia` a mano fuera de aquí.
     */
    public function pendienteDeDetalle(DetalleEntrega $detalle): int
    {
        return $this->pendientesPorDetalle(collect([$detalle]))[$detalle->getKey()] ?? 0;
    }

    /**
     * Versión en lote de `pendienteDeDetalle()` para evitar N+1 al presentar
     * todos los renglones de una entrega o de un colaborador a la vez.
     *
     * @param  Collection<int, DetalleEntrega>  $detalles
     * @return array<int, int> pendiente indexado por `detalle_entrega_id`
     */
    public function pendientesPorDetalle(Collection $detalles): array
    {
        if ($detalles->isEmpty()) {
            return [];
        }

        $devueltoPorDetalle = DetalleDevolucion::query()
            ->whereIn('detalle_entrega_id', $detalles->pluck('id'))
            ->whereHas('devolucion', fn ($q) => $q->where('estado', EstadoDevolucion::Confirmada))
            ->selectRaw('detalle_entrega_id, SUM(cantidad) as total')
            ->groupBy('detalle_entrega_id')
            ->pluck('total', 'detalle_entrega_id');

        $incidenciaPorDetalle = IncidenciaCustodia::query()
            ->whereIn('detalle_entrega_id', $detalles->pluck('id'))
            ->selectRaw('detalle_entrega_id, SUM(cantidad) as total')
            ->groupBy('detalle_entrega_id')
            ->pluck('total', 'detalle_entrega_id');

        $redistribuidoPorDetalle = $this->redistribuidoPorDetalle($detalles->pluck('id')->all());

        return $detalles
            ->mapWithKeys(fn (DetalleEntrega $d): array => [
                $d->getKey() => max(
                    (int) $d->cantidad
                        - (int) ($devueltoPorDetalle[$d->getKey()] ?? 0)
                        - (int) ($incidenciaPorDetalle[$d->getKey()] ?? 0)
                        - (int) ($redistribuidoPorDetalle[$d->getKey()] ?? 0),
                    0
                ),
            ])
            ->all();
    }

    /**
     * Piezas que ya salieron de cada renglón hacia OTRO colaborador por
     * redistribución (renglones hijos con `detalle_origen_id`). Cuenta toda
     * redistribución no anulada — incluida una todavía `pendiente_firma`, que
     * sólo existe dentro de la transacción que la crea — para que nunca se
     * pueda sobregirar la custodia del origen.
     *
     * @param  array<int, int>  $idsDetalle
     * @return array<int, int> redistribuido indexado por `detalle_entrega_id`
     */
    public function redistribuidoPorDetalle(array $idsDetalle): array
    {
        if ($idsDetalle === []) {
            return [];
        }

        return DetalleEntrega::query()
            ->whereIn('detalle_origen_id', $idsDetalle)
            ->whereHas('entrega', fn ($q) => $q->where('estado', '!=', EstadoEntrega::Anulada))
            ->selectRaw('detalle_origen_id, SUM(cantidad) as total')
            ->groupBy('detalle_origen_id')
            ->pluck('total', 'detalle_origen_id')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }

    /**
     * Filtra una query de `EntregaUniforme` para dejar sólo las que TODAVÍA
     * tienen custodia pendiente real (al menos un renglón por cantidad con
     * saldo, o al menos una unidad identificada todavía asignada). Es el
     * filtro que debe alimentar cualquier selector de "Entrega de origen":
     * una entrega totalmente devuelta y confirmada nunca debe volver a
     * ofrecerse. Correlacionado en SQL, sin N+1.
     *
     * @param  Builder<EntregaUniforme>  $query
     * @return Builder<EntregaUniforme>
     */
    public function filtrarConPendiente(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereHas('detalles', function (Builder $d) {
                $d->whereNull('unidad_activo_id')
                    ->whereRaw('detalles_entrega.cantidad > (
                        select coalesce(sum(dd.cantidad), 0)
                        from detalles_devolucion dd
                        inner join devoluciones dv on dv.id = dd.devolucion_id
                        where dd.detalle_entrega_id = detalles_entrega.id
                        and dv.estado = ?
                    ) + (
                        select coalesce(sum(ic.cantidad), 0)
                        from incidencias_custodia ic
                        where ic.detalle_entrega_id = detalles_entrega.id
                    ) + (
                        select coalesce(sum(dr.cantidad), 0)
                        from detalles_entrega dr
                        inner join entregas_uniformes er on er.id = dr.entrega_uniforme_id
                        where dr.detalle_origen_id = detalles_entrega.id
                        and er.estado != ?
                        and er.deleted_at is null
                    )', [EstadoDevolucion::Confirmada->value, EstadoEntrega::Anulada->value]);
            })->orWhereHas('detalles', function (Builder $d) {
                // La unidad sigue asignada Y sigue en manos del colaborador
                // de ESTA entrega: si se redistribuyó, ya es custodia de otro.
                $d->whereNotNull('unidad_activo_id')
                    ->whereHas('unidadActivo', fn (Builder $u) => $u
                        ->where('estado', EstadoUnidadActivo::Asignada)
                        ->whereColumn('unidades_activo.colaborador_id', 'entregas_uniformes.colaborador_id'));
            });
        });
    }

    /**
     * Renglón de entrega bajo el que una unidad identificada está
     * ACTUALMENTE asignada: el más reciente que todavía no tiene una
     * devolución CONFIRMADA. Una unidad puede haberse entregado varias veces
     * a lo largo de su vida (entregada → devuelta → vuelta a entregar); esto
     * localiza la entrega vigente, no todo el historial.
     */
    public function entregaActualDeUnidad(UnidadActivo $unidad): ?DetalleEntrega
    {
        return DetalleEntrega::query()
            ->where('unidad_activo_id', $unidad->getKey())
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('detalles_devolucion as dd')
                    ->join('devoluciones as dv', 'dv.id', '=', 'dd.devolucion_id')
                    ->whereColumn('dd.detalle_entrega_id', 'detalles_entrega.id')
                    ->where('dv.estado', EstadoDevolucion::Confirmada->value);
            })
            ->with('entrega:id,folio')
            ->latest('id')
            ->first();
    }

    /**
     * KPI "Activos asignados": total de PIEZAS FÍSICAS que el colaborador
     * tiene actualmente bajo custodia — una unidad identificada cuenta como 1
     * pieza; un renglón de cantidad cuenta su saldo pendiente (entregado −
     * devuelto CONFIRMADO). Es la cantidad física, no el número de renglones
     * distintos: 3 playeras entregadas en un solo renglón cuentan como 3.
     * Reutiliza exactamente los mismos criterios que `pendientes()`, sin
     * volver a armar el detalle legible (evita el `with('activo')` y el join
     * de `entregaActualDeUnidad()` por unidad, innecesarios para un total).
     *
     * @param  array<int, int>|null  $idsEmpresasAutorizadas  Aislamiento histórico (ver `ColaboradorController::show`): `null` = sin acotar (uso interno/negocio), un arreglo = sólo cuenta lo que esas empresas autorizan.
     */
    public function totalPiezasPendientes(Colaborador $colaborador, ?array $idsEmpresasAutorizadas = null): int
    {
        $unidades = UnidadActivo::query()
            ->where('colaborador_id', $colaborador->getKey())
            ->where('estado', EstadoUnidadActivo::Asignada)
            ->when($idsEmpresasAutorizadas !== null, fn (Builder $q) => $q->whereIn('empresa_id', $idsEmpresasAutorizadas))
            ->count();

        $cantidad = array_sum(array_column($this->cantidadesPendientes($colaborador, $idsEmpresasAutorizadas), 'pendiente'));

        return $unidades + $cantidad;
    }

    /**
     * Renglones de cantidad con saldo pendiente de devolución de un
     * colaborador (todas sus entregas firmadas/corregidas).
     *
     * @param  array<int, int>|null  $idsEmpresasAutorizadas  Acota a estas empresas cuando no es `null` — ver `totalPiezasPendientes()`.
     * @return list<array{activo: string, talla: string|null, pendiente: int, folio: string|null, entrega_id: int|null, detalle_entrega_id: int, activo_id: int, talla_id: int|null}>
     */
    private function cantidadesPendientes(Colaborador $colaborador, ?array $idsEmpresasAutorizadas = null): array
    {
        /** @var Collection<int, DetalleEntrega> $detalles */
        $detalles = DetalleEntrega::query()
            ->whereNull('unidad_activo_id')
            ->whereHas('entrega', fn ($q) => $q
                ->where('colaborador_id', $colaborador->getKey())
                ->whereIn('estado', ['firmada', 'corregida'])
                ->when($idsEmpresasAutorizadas !== null, fn ($q2) => $q2->whereIn('empresa_id', $idsEmpresasAutorizadas)))
            ->with('entrega:id,folio')
            ->get();

        if ($detalles->isEmpty()) {
            return [];
        }

        $pendientePorDetalle = $this->pendientesPorDetalle($detalles);

        $filas = [];

        foreach ($detalles as $detalle) {
            $pendiente = $pendientePorDetalle[$detalle->id] ?? 0;

            if ($pendiente <= 0) {
                continue;
            }

            $filas[] = [
                'activo' => $detalle->activo_nombre_snapshot,
                'talla' => $detalle->talla_valor_snapshot,
                'pendiente' => $pendiente,
                'folio' => $detalle->entrega?->folio,
                'entrega_id' => $detalle->entrega_uniforme_id,
                'detalle_entrega_id' => $detalle->id,
                'activo_id' => $detalle->activo_id,
                'talla_id' => $detalle->talla_id,
            ];
        }

        return $filas;
    }
}
