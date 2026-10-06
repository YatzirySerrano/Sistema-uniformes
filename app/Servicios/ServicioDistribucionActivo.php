<?php

namespace App\Servicios;

use App\Enums\EstadoDevolucion;
use App\Enums\EstadoEntrega;
use App\Enums\EstadoUnidadActivo;
use App\Enums\FinalidadCustodia;
use App\Models\Activo;
use App\Models\Colaborador;
use App\Models\DetalleEntrega;
use App\Models\SaldoInventario;
use App\Models\UnidadActivo;
use Illuminate\Database\Eloquent\Collection;

/**
 * "Distribución actual del activo": DÓNDE está hoy cada pieza y con qué
 * finalidad — en almacén (por almacén + variante) o bajo custodia (por
 * custodio + variante + finalidad). Sólo lectura.
 *
 * La custodia por cantidad sale de la MISMA fuente que el resto del sistema
 * (`ServicioCustodiaColaborador::pendientesPorDetalle()`: entregado −
 * devuelto confirmado − incidencias − redistribuido), así que una
 * redistribución mueve piezas de un custodio a otro sin duplicarlas y nunca
 * aparece como una fila anónima. Nunca se mezclan finalidades ni custodios:
 * cada fila es un (grupo, custodio/almacén, variante).
 */
class ServicioDistribucionActivo
{
    public const GRUPO_ALMACEN = 'almacen';

    public const GRUPO_SIN_CLASIFICAR = 'sin_clasificar';

    /** Tope de unidades listadas (el listado completo vive en Unidades). */
    public const LIMITE_UNIDADES = 300;

    public function __construct(private readonly ServicioCustodiaColaborador $custodia) {}

    /**
     * @return array{
     *     totales: array{almacen: int, uso_personal: int, redistribucion: int, sin_clasificar: int},
     *     filas: list<array{grupo: 'almacen'|'uso_personal'|'redistribucion'|'sin_clasificar', grupo_etiqueta: string, almacen: string|null, custodio: array{id: int, nombre_completo: string, numero_empleado: string|null, empresa: string|null, sucursal: string|null, otra_empresa: bool}|null, talla: string|null, cantidad: int}>,
     *     unidades: list<array{codigo: string, public_token: string, grupo: 'almacen'|'uso_personal'|'redistribucion'|'sin_clasificar', grupo_etiqueta: string, custodio: array{id: int, nombre_completo: string, numero_empleado: string|null, empresa: string|null, sucursal: string|null, otra_empresa: bool}|null, almacen: string|null, condicion: string, finalidad_etiqueta: string|null}>,
     *     unidades_total: int
     * }
     */
    public function paraActivo(Activo $activo): array
    {
        $filas = [...$this->filasAlmacen($activo), ...$this->filasCustodia($activo)];
        [$unidades, $unidadesTotal] = $this->unidades($activo);

        $totales = ['almacen' => 0, 'uso_personal' => 0, 'redistribucion' => 0, 'sin_clasificar' => 0];
        foreach ($filas as $fila) {
            $totales[$fila['grupo']] += $fila['cantidad'];
        }

        // Las unidades en almacén / bajo custodia también cuentan en sus
        // totales (1 pieza cada una); las de baja no están "distribuidas".
        foreach ($unidades as $unidad) {
            $totales[$unidad['grupo']]++;
        }

        return [
            'totales' => $totales,
            'filas' => $filas,
            'unidades' => $unidades,
            'unidades_total' => $unidadesTotal,
        ];
    }

    /**
     * @return list<array{grupo: 'almacen', grupo_etiqueta: string, almacen: string|null, custodio: null, talla: string|null, cantidad: int}>
     */
    private function filasAlmacen(Activo $activo): array
    {
        return array_values(SaldoInventario::query()
            ->where('empresa_id', $activo->empresa_id)
            ->where('activo_id', $activo->id)
            ->where('cantidad', '>', 0)
            ->with(['almacen:id,nombre', 'talla:id,valor,orden'])
            ->get()
            ->sortBy(fn (SaldoInventario $s): string => ($s->almacen->nombre ?? '').'|'.str_pad((string) ($s->talla->orden ?? 0), 6, '0', STR_PAD_LEFT))
            ->map(fn (SaldoInventario $s): array => [
                'grupo' => 'almacen',
                'grupo_etiqueta' => 'En almacén',
                'almacen' => $s->almacen?->nombre,
                'custodio' => null,
                'talla' => $s->talla?->valor,
                'cantidad' => (int) $s->cantidad,
            ])
            ->all());
    }

    /**
     * @return list<array{grupo: 'uso_personal'|'redistribucion'|'sin_clasificar', grupo_etiqueta: string, almacen: null, custodio: array{id: int, nombre_completo: string, numero_empleado: string|null, empresa: string|null, sucursal: string|null, otra_empresa: bool}, talla: string|null, cantidad: int}>
     */
    private function filasCustodia(Activo $activo): array
    {
        // Sin filtrar por la empresa de la ENTREGA: tras una redistribución
        // hacia otra empresa autorizada, la entrega es de la empresa destino
        // pero las piezas siguen siendo de este activo (su dueña no cambia).
        // Filtrarla las hacía desaparecer, porque el renglón del custodio
        // anterior ya las descuenta como redistribuidas.
        /** @var Collection<int, DetalleEntrega> $detalles */
        $detalles = DetalleEntrega::query()
            ->where('activo_id', $activo->id)
            ->whereNull('unidad_activo_id')
            ->whereHas('entrega', fn ($q) => $q
                ->whereIn('estado', [EstadoEntrega::Firmada->value, EstadoEntrega::Corregida->value]))
            ->with('entrega:id,colaborador_id', 'entrega.colaborador:id,nombre_completo,numero_empleado,empresa_id,sucursal_id', 'entrega.colaborador.empresa:id,nombre_comercial', 'entrega.colaborador.sucursal:id,nombre')
            ->orderBy('id')
            ->get();

        $pendientes = $this->custodia->pendientesPorDetalle($detalles);

        $filas = [];
        foreach ($detalles as $detalle) {
            $pendiente = $pendientes[$detalle->id] ?? 0;
            $colaborador = $detalle->entrega?->colaborador;

            if ($pendiente <= 0 || $colaborador === null) {
                continue;
            }

            $grupo = $this->grupoDeFinalidad($detalle->finalidad);
            $clave = $grupo.'|'.$colaborador->id.'|'.($detalle->talla_valor_snapshot ?? '');
            $filas[$clave] ??= [
                'grupo' => $grupo,
                'grupo_etiqueta' => FinalidadCustodia::etiquetaDe($detalle->finalidad),
                'almacen' => null,
                'custodio' => $this->custodio($colaborador, $activo),
                'talla' => $detalle->talla_valor_snapshot,
                'cantidad' => 0,
            ];
            $filas[$clave]['cantidad'] += $pendiente;
        }

        $orden = ['uso_personal' => 0, 'redistribucion' => 1, self::GRUPO_SIN_CLASIFICAR => 2];

        $filas = array_values($filas);
        usort($filas, fn (array $a, array $b): int => [$orden[$a['grupo']], $a['custodio']['nombre_completo'], $a['talla'] ?? '']
            <=> [$orden[$b['grupo']], $b['custodio']['nombre_completo'], $b['talla'] ?? '']);

        return $filas;
    }

    /**
     * Unidades de seguimiento individual que hoy están en almacén o bajo
     * custodia (las de baja ya no forman parte de la distribución).
     *
     * @return array{0: list<array{codigo: string, public_token: string, grupo: 'almacen'|'uso_personal'|'redistribucion'|'sin_clasificar', grupo_etiqueta: string, custodio: array{id: int, nombre_completo: string, numero_empleado: string|null, empresa: string|null, sucursal: string|null, otra_empresa: bool}|null, almacen: string|null, condicion: string, finalidad_etiqueta: string|null}>, 1: int}
     */
    private function unidades(Activo $activo): array
    {
        $consulta = UnidadActivo::query()
            ->where('activo_id', $activo->id)
            ->whereIn('estado', [EstadoUnidadActivo::EnAlmacen, EstadoUnidadActivo::Asignada]);

        $total = (clone $consulta)->count();

        if ($total === 0) {
            return [[], 0];
        }

        $unidades = $consulta
            ->with(['almacen:id,nombre', 'colaborador:id,nombre_completo,numero_empleado,empresa_id,sucursal_id', 'colaborador.empresa:id,nombre_comercial', 'colaborador.sucursal:id,nombre'])
            ->orderBy('codigo')
            ->limit(self::LIMITE_UNIDADES)
            ->get();

        $finalidades = $this->finalidadesVigentes($unidades->where('estado', EstadoUnidadActivo::Asignada)->pluck('id')->all());

        $filas = array_values($unidades->map(function (UnidadActivo $u) use ($finalidades, $activo): array {
            $asignada = $u->estado === EstadoUnidadActivo::Asignada && $u->colaborador !== null;
            $finalidad = $finalidades[$u->id] ?? null;

            return [
                'codigo' => $u->codigo,
                'public_token' => $u->public_token,
                'grupo' => $asignada ? $this->grupoDeFinalidad($finalidad) : 'almacen',
                'grupo_etiqueta' => $asignada ? FinalidadCustodia::etiquetaDe($finalidad) : 'En almacén',
                'custodio' => $asignada ? $this->custodio($u->colaborador, $activo) : null,
                'almacen' => $u->almacen?->nombre,
                'condicion' => $u->condicion->etiqueta(),
                'finalidad_etiqueta' => $asignada ? FinalidadCustodia::etiquetaDe($finalidad) : null,
            ];
        })->all());

        return [$filas, $total];
    }

    /**
     * Finalidad del renglón de entrega VIGENTE de cada unidad asignada (el más
     * reciente sin devolución confirmada) — mismo criterio que
     * `ServicioCustodiaColaborador::entregaActualDeUnidad()`, en lote.
     *
     * @param  array<int, int>  $idsUnidad
     * @return array<int, FinalidadCustodia|null>
     */
    private function finalidadesVigentes(array $idsUnidad): array
    {
        if ($idsUnidad === []) {
            return [];
        }

        return DetalleEntrega::query()
            ->whereIn('unidad_activo_id', $idsUnidad)
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('detalles_devolucion as dd')
                    ->join('devoluciones as dv', 'dv.id', '=', 'dd.devolucion_id')
                    ->whereColumn('dd.detalle_entrega_id', 'detalles_entrega.id')
                    ->where('dv.estado', EstadoDevolucion::Confirmada->value);
            })
            ->orderBy('id')
            ->get(['id', 'unidad_activo_id', 'finalidad'])
            // Orden ascendente + keyBy: gana el renglón más reciente.
            ->keyBy('unidad_activo_id')
            ->map(fn (DetalleEntrega $d): ?FinalidadCustodia => $d->finalidad)
            ->all();
    }

    /**
     * @return 'uso_personal'|'redistribucion'|'sin_clasificar'
     */
    private function grupoDeFinalidad(?FinalidadCustodia $finalidad): string
    {
        return match ($finalidad) {
            FinalidadCustodia::UsoPersonal => 'uso_personal',
            FinalidadCustodia::Redistribucion => 'redistribucion',
            null => self::GRUPO_SIN_CLASIFICAR,
        };
    }

    /**
     * Custodio actual. `otra_empresa`: pertenece a una empresa distinta de la
     * DUEÑA del activo (custodia recibida por redistribución entre empresas);
     * la propiedad no cambia, sólo dónde están las piezas.
     *
     * @return array{id: int, nombre_completo: string, numero_empleado: string|null, empresa: string|null, sucursal: string|null, otra_empresa: bool}
     */
    private function custodio(Colaborador $colaborador, Activo $activo): array
    {
        return [
            'id' => $colaborador->id,
            'nombre_completo' => $colaborador->nombre_completo,
            'numero_empleado' => $colaborador->numero_empleado,
            'empresa' => $colaborador->empresa?->nombre_comercial,
            'sucursal' => $colaborador->sucursal?->nombre,
            'otra_empresa' => $colaborador->empresa_id !== $activo->empresa_id,
        ];
    }
}
