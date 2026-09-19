<?php

namespace App\Acciones;

use App\Enums\TipoControlActivo;
use App\Enums\TipoReserva;
use App\Models\Activo;
use App\Models\Conjunto;
use App\Models\ConjuntoComponente;
use App\Models\Reserva;
use App\Models\Talla;
use App\Servicios\ServicioInventario;
use App\Servicios\ServicioReservas;
use App\Servicios\ServicioUnidadesActivo;
use Illuminate\Support\Facades\DB;

/**
 * Recalcula, de forma ATÓMICA, el apartado temporal de inventario de UN
 * borrador de Entrega completo (nunca 40 reservas sueltas sin coordinar).
 * Se llama cada vez que el usuario termina de editar una selección válida en
 * el paso 2 del wizard (debounce en el frontend).
 *
 * Agrega TODA la demanda antes de comparar contra existencias: dos renglones
 * sueltos iguales, un artículo suelto + un conjunto que usa el mismo
 * activo+talla, o dos conjuntos distintos que comparten componente, se suman
 * por clave `activo_id + talla_id` — así se detecta el caso "12 solicitadas
 * entre artículos y conjuntos, sólo 6 disponibles" ANTES de llegar a firmas.
 *
 * Reserva sólo lo que realmente cabe (`min(solicitado, disponible_efectivo)`
 * por clave): nunca aparta más de lo que hay, para no bloquear a otros
 * usuarios con demanda que este borrador tampoco podría usar. `ok=false` en
 * la respuesta es la señal para el frontend de que no debe avanzar al paso
 * de firmas — el borrador sigue existiendo con lo que sí pudo apartar.
 *
 * Esta reserva es una capa de UX/concurrencia PREVIA: `CrearEntregaUniforme`
 * conserva íntegras sus validaciones y candados propios al confirmar.
 */
class ReservarInventarioEntrega
{
    public function __construct(
        private readonly ServicioReservas $reservas,
        private readonly ServicioInventario $inventario,
        private readonly ServicioUnidadesActivo $unidadesActivo,
    ) {}

    /**
     * @param  array<int, array{activo_id: int|string|null, talla_id?: int|string|null, cantidad: int|string|null}>  $activos  Reglas laxas (ver controller): un renglón a medio llenar puede traer `''`/`null`.
     * @param  array<int, array{unidad_activo_id: int|string|null}>  $unidades
     * @param  array<int, array{conjunto_id: int|string|null, cantidad: int|string|null, variantes?: array<int|string, int|string|null>}>  $conjuntos
     * @return array{
     *     token: string, expira_en: string, ok: bool,
     *     lineas_cantidad: array<int, array{activo_id: int, talla_id: ?int, activo_nombre: ?string, talla_valor: ?string, disponible_efectivo: int, solicitado_combinado: int, suficiente: bool}>,
     *     lineas_unidad: array<int, array{unidad_activo_id: int, ok: bool, motivo: ?string}>,
     *     conjuntos: array<int, array{indice: int, conjunto_id: int, suficiente: bool, requiere_seleccion_variante: bool}>,
     * }
     */
    public function ejecutar(
        string $token,
        int $userId,
        int $empresaId,
        int $almacenId,
        ?int $colaboradorId,
        array $activos,
        array $unidades,
        array $conjuntos,
    ): array {
        return DB::transaction(function () use ($token, $userId, $empresaId, $almacenId, $colaboradorId, $activos, $unidades, $conjuntos): array {
            $reserva = $this->reservas->obtenerOCrearCabecera($token, TipoReserva::Entrega, $userId, $empresaId, $almacenId, $colaboradorId, null);

            // --- 1. Agregar demanda de CANTIDAD por clave activo+talla ---
            /** @var array<string, array{activo_id: int, talla_id: ?int, cantidad: int}> $demanda */
            $demanda = [];
            $acumular = function (int $activoId, ?int $tallaId, int $cantidad) use (&$demanda): void {
                if ($cantidad <= 0) {
                    return;
                }
                $clave = $activoId.'-'.($tallaId ?? '0');
                $demanda[$clave] ??= ['activo_id' => $activoId, 'talla_id' => $tallaId, 'cantidad' => 0];
                $demanda[$clave]['cantidad'] += $cantidad;
            };

            foreach ($activos as $fila) {
                if (($fila['activo_id'] ?? '') === '') {
                    continue;
                }
                $tallaId = ($fila['talla_id'] ?? null) !== null ? (int) $fila['talla_id'] : null;
                $acumular((int) $fila['activo_id'], $tallaId, (int) ($fila['cantidad'] ?? 0));
            }

            $conjuntoIds = collect($conjuntos)->pluck('conjunto_id')->filter(fn ($v) => $v !== '')->map(fn ($v) => (int) $v)->unique();
            $conjuntosCargados = Conjunto::query()->with('componentes.activo')->whereIn('id', $conjuntoIds)->get()->keyBy('id');

            // Demanda de UNIDADES por conjunto: activo_id => cantidad total necesaria.
            /** @var array<int, int> $demandaUnidadesPorActivo */
            $demandaUnidadesPorActivo = [];
            $reporteConjuntos = [];

            foreach ($conjuntos as $indice => $fila) {
                if (($fila['conjunto_id'] ?? '') === '') {
                    continue;
                }
                $conjunto = $conjuntosCargados->get((int) $fila['conjunto_id']);
                if ($conjunto === null) {
                    continue;
                }
                $cantidadConjuntos = (int) ($fila['cantidad'] ?? 0);
                $variantesElegidas = is_array($fila['variantes'] ?? null) ? $fila['variantes'] : [];
                $requiereSeleccionVariante = false;

                foreach ($conjunto->componentes as $componente) {
                    /** @var ConjuntoComponente $componente */
                    $activoComponente = $componente->activo;
                    if ($activoComponente === null || ! $activoComponente->activo || $cantidadConjuntos <= 0) {
                        continue;
                    }

                    $tallaId = Conjunto::resolverTallaComponente($componente, $variantesElegidas);
                    if ($componente->talla_libre && $tallaId === null) {
                        $requiereSeleccionVariante = true;

                        continue;
                    }

                    $cantidadNecesaria = $componente->cantidad_requerida * $cantidadConjuntos;

                    if ($activoComponente->tipo_control === TipoControlActivo::Cantidad) {
                        $acumular($activoComponente->id, $tallaId, $cantidadNecesaria);
                    } else {
                        $demandaUnidadesPorActivo[$activoComponente->id] = ($demandaUnidadesPorActivo[$activoComponente->id] ?? 0) + $cantidadNecesaria;
                    }
                }

                $reporteConjuntos[] = [
                    'indice' => $indice,
                    'conjunto_id' => $conjunto->id,
                    'requiere_seleccion_variante' => $requiereSeleccionVariante,
                    'cantidad' => $cantidadConjuntos,
                    // Se completa tras resolver disponibilidad por clave / unidades, abajo.
                ];
            }

            // --- 2. Bloquear saldos en orden ESTABLE (activo_id, talla_id) y reservar lo que quepa ---
            $claves = array_keys($demanda);
            sort($claves);

            $lineasCantidad = [];
            $disponibleEfectivoPorClave = [];

            foreach ($claves as $clave) {
                $item = $demanda[$clave];
                $saldo = $this->inventario->lockearSaldo($empresaId, $almacenId, $item['activo_id'], $item['talla_id']);
                $reservadoOtros = $this->reservas->demandaCantidadDeOtros(TipoReserva::Entrega, $empresaId, $almacenId, $item['activo_id'], $item['talla_id'], $token);
                $disponibleEfectivo = max(0, (int) $saldo->cantidad - $reservadoOtros);
                $aReservar = min($item['cantidad'], $disponibleEfectivo);

                if ($aReservar > 0) {
                    $reserva->renglones()->create([
                        'activo_id' => $item['activo_id'],
                        'talla_id' => $item['talla_id'],
                        'cantidad' => $aReservar,
                    ]);
                }

                $disponibleEfectivoPorClave[$clave] = $disponibleEfectivo;

                $lineasCantidad[] = [
                    'activo_id' => $item['activo_id'],
                    'talla_id' => $item['talla_id'],
                    'activo_nombre' => Activo::query()->whereKey($item['activo_id'])->value('nombre'),
                    'talla_valor' => $item['talla_id'] !== null ? Talla::query()->whereKey($item['talla_id'])->value('valor') : null,
                    'disponible_efectivo' => $disponibleEfectivo,
                    'solicitado_combinado' => $item['cantidad'],
                    'suficiente' => $item['cantidad'] <= $disponibleEfectivo,
                ];
            }

            // --- 3. Unidades sueltas: cada una debe seguir entregable y libre de otras reservas ---
            $lineasUnidad = [];
            $unidadesYaUsadasEnBorrador = [];
            $idsUnidadSolicitados = [];

            foreach ($unidades as $fila) {
                if (($fila['unidad_activo_id'] ?? '') === '') {
                    continue;
                }
                $unidadId = (int) $fila['unidad_activo_id'];
                if (in_array($unidadId, $idsUnidadSolicitados, true)) {
                    continue;
                }
                $idsUnidadSolicitados[] = $unidadId;

                $unidad = $this->unidadesActivo->bloquearYVerificarEntregableSuave($unidadId);
                if ($unidad === null) {
                    $lineasUnidad[] = ['unidad_activo_id' => $unidadId, 'ok' => false, 'motivo' => 'Esa unidad ya no está disponible para entrega.'];

                    continue;
                }

                if ($this->reservas->unidadApartadaPorOtro($unidadId, $token)) {
                    $lineasUnidad[] = ['unidad_activo_id' => $unidadId, 'ok' => false, 'motivo' => 'Esta unidad acaba de ser apartada por otra operación.'];

                    continue;
                }

                $reserva->renglones()->create(['unidad_activo_id' => $unidadId]);
                $unidadesYaUsadasEnBorrador[] = $unidadId;
                $lineasUnidad[] = ['unidad_activo_id' => $unidadId, 'ok' => true, 'motivo' => null];
            }

            // --- 4. Unidades requeridas por conjuntos: se eligen candidatas concretas ---
            $unidadesEncontradasPorActivo = [];
            foreach ($demandaUnidadesPorActivo as $activoId => $cantidadNecesaria) {
                $excluir = array_merge(
                    $unidadesYaUsadasEnBorrador,
                    $this->reservas->unidadesApartadasPorOtros($activoId, $almacenId, $token),
                );
                $candidatas = $this->unidadesActivo->candidatosParaReserva($activoId, $almacenId, $cantidadNecesaria, $excluir);

                foreach ($candidatas as $unidad) {
                    $reserva->renglones()->create(['unidad_activo_id' => $unidad->id, 'activo_id' => $activoId]);
                    $unidadesYaUsadasEnBorrador[] = $unidad->id;
                }

                $unidadesEncontradasPorActivo[$activoId] = $candidatas->count();
            }

            // --- 5. Suficiencia por renglón de conjunto (recorre sus mismos componentes) ---
            foreach ($reporteConjuntos as &$rep) {
                $conjunto = $conjuntosCargados->get($rep['conjunto_id']);
                $suficiente = ! $rep['requiere_seleccion_variante'];
                $variantesElegidas = is_array($conjuntos[$rep['indice']]['variantes'] ?? null) ? $conjuntos[$rep['indice']]['variantes'] : [];

                if ($suficiente && $conjunto !== null) {
                    foreach ($conjunto->componentes as $componente) {
                        $activoComponente = $componente->activo;
                        if ($activoComponente === null || ! $activoComponente->activo) {
                            $suficiente = false;

                            continue;
                        }
                        $tallaId = Conjunto::resolverTallaComponente($componente, $variantesElegidas);
                        $cantidadNecesaria = $componente->cantidad_requerida * $rep['cantidad'];

                        if ($activoComponente->tipo_control === TipoControlActivo::Cantidad) {
                            $clave = $activoComponente->id.'-'.($tallaId ?? '0');
                            $item = $demanda[$clave] ?? null;
                            $disponible = $disponibleEfectivoPorClave[$clave] ?? 0;
                            if ($item === null || $item['cantidad'] > $disponible) {
                                $suficiente = false;
                            }
                        } else {
                            $encontradas = $unidadesEncontradasPorActivo[$activoComponente->id] ?? 0;
                            $necesarias = $demandaUnidadesPorActivo[$activoComponente->id] ?? 0;
                            if ($encontradas < $necesarias) {
                                $suficiente = false;
                            }
                        }
                    }
                }

                $rep['suficiente'] = $suficiente;
                unset($rep['cantidad']);
            }
            unset($rep);

            $ok = ! in_array(false, array_column($lineasCantidad, 'suficiente'), true)
                && ! in_array(false, array_column($lineasUnidad, 'ok'), true)
                && ! in_array(false, array_column($reporteConjuntos, 'suficiente'), true);

            return [
                'token' => $reserva->token,
                'expira_en' => $reserva->expira_en->toIso8601String(),
                'ok' => $ok,
                'lineas_cantidad' => $lineasCantidad,
                'lineas_unidad' => $lineasUnidad,
                'conjuntos' => $reporteConjuntos,
            ];
        });
    }
}
