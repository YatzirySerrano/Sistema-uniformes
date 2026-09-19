<?php

namespace App\Servicios;

use App\Enums\TipoReserva;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\RenglonReserva;
use App\Models\Reserva;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Lectura/escritura de bajo nivel de `Reserva`/`RenglonReserva`, compartida
 * por `ReservarInventarioEntrega` y `ReservarCustodiaDevolucion` sin mezclar
 * sus reglas de negocio (cada Acción decide QUÉ demanda agregar y contra qué
 * compara; este servicio sólo sabe leer/escribir filas de reserva).
 *
 * Todo método que lee "cuánto reservaron OTROS" filtra siempre por
 * `Reserva::scopeActiva()` — una reserva vencida nunca bloquea nada aunque la
 * fila siga existiendo físicamente hasta la limpieza.
 */
class ServicioReservas
{
    /**
     * Acota una subconsulta `whereHas('reserva', ...)` a reservas activas,
     * excluyendo opcionalmente el propio token del borrador — nunca se
     * descuenta una reserva de sí misma. Repite a mano la condición de
     * `Reserva::scopeActiva()` (en vez de llamarlo) porque dentro de un
     * `whereHas()` PHPStan/Larastan no puede resolver el generic del
     * `Builder` del closure al modelo concreto de la relación.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function filtrarActivaExcluyendoPropia(Builder $query, ?string $excluirToken): Builder
    {
        return $query
            ->whereNull('consumida_en')
            ->whereNull('liberada_en')
            ->where('expira_en', '>', now())
            ->when($excluirToken !== null, fn (Builder $q) => $q->where('token', '!=', $excluirToken));
    }

    /**
     * Cuánta CANTIDAD de un activo+talla tienen apartada otras reservas
     * ACTIVAS del mismo tipo (nunca se mezcla demanda de Entrega con
     * demanda de Devolución: son dos apartados conceptualmente distintos
     * sobre datos distintos — cantidad de almacén vs. custodia pendiente).
     */
    public function demandaCantidadDeOtros(TipoReserva $tipo, int $empresaId, ?int $almacenId, int $activoId, ?int $tallaId, ?string $excluirToken = null): int
    {
        $query = RenglonReserva::query()
            ->whereNotNull('activo_id')
            ->whereNotNull('cantidad')
            ->where('activo_id', $activoId)
            ->when($tallaId === null, fn ($q) => $q->whereNull('talla_id'), fn ($q) => $q->where('talla_id', $tallaId))
            ->whereHas('reserva', function (Builder $q) use ($tipo, $empresaId, $almacenId, $excluirToken): void {
                $this->filtrarActivaExcluyendoPropia($q, $excluirToken)
                    ->where('tipo', $tipo)
                    ->where('empresa_id', $empresaId)
                    ->when($almacenId !== null, fn (Builder $q2) => $q2->where('almacen_id', $almacenId));
            });

        return (int) $query->sum('cantidad');
    }

    /**
     * Cuánta cantidad de un `DetalleEntrega` (custodia pendiente) tienen
     * apartada otras reservas de DEVOLUCIÓN activas.
     */
    public function custodiaApartadaDeOtros(int $detalleEntregaId, ?string $excluirToken = null): int
    {
        $query = RenglonReserva::query()
            ->where('detalle_entrega_id', $detalleEntregaId)
            ->whereNotNull('cantidad')
            ->whereHas('reserva', function (Builder $q) use ($excluirToken): void {
                $this->filtrarActivaExcluyendoPropia($q, $excluirToken)->where('tipo', TipoReserva::Devolucion);
            });

        return (int) $query->sum('cantidad');
    }

    /**
     * Igual que `demandaCantidadDeOtros()` pero para VARIOS activos en UNA
     * sola consulta agrupada — usarlo para descontar reservas en una página
     * de resultados completa (p. ej. `ActivoController::buscar()`) en vez de
     * una consulta por activo/talla, que escalaría con el tamaño de la
     * página (N+1).
     *
     * @param  array<int, int>  $activoIds
     * @return array<string, int> clave `activo_id-talla_id` ('0' = sin variante)
     */
    public function demandaCantidadPorActivos(TipoReserva $tipo, int $empresaId, ?int $almacenId, array $activoIds, ?string $excluirToken = null): array
    {
        if ($activoIds === []) {
            return [];
        }

        // `toBase()`: filas planas (`stdClass`), no modelos — el resultado
        // no mapea 1:1 a un `RenglonReserva` real (está agrupado/sumado), así
        // que hidratarlo como Eloquent no tendría sentido. `CONCAT()` no es
        // portable entre MySQL/SQLite; la clave compuesta se arma en PHP.
        return RenglonReserva::query()
            ->whereNotNull('activo_id')
            ->whereNotNull('cantidad')
            ->whereIn('activo_id', $activoIds)
            ->whereHas('reserva', function (Builder $q) use ($tipo, $empresaId, $almacenId, $excluirToken): void {
                $this->filtrarActivaExcluyendoPropia($q, $excluirToken)
                    ->where('tipo', $tipo)
                    ->where('empresa_id', $empresaId)
                    ->when($almacenId !== null, fn (Builder $q2) => $q2->where('almacen_id', $almacenId));
            })
            ->selectRaw('activo_id, talla_id, SUM(cantidad) as total')
            ->groupBy('activo_id', 'talla_id')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $fila): array => [$fila->activo_id.'-'.($fila->talla_id ?? '0') => (int) $fila->total])
            ->all();
    }

    /**
     * Igual que `unidadesApartadasPorOtros()` pero para VARIOS activos de un
     * mismo almacén en UNA sola consulta agrupada — mismo motivo que
     * `demandaCantidadPorActivos()`.
     *
     * @param  array<int, int>  $activoIds
     * @return array<int, int> total de unidades apartadas por `activo_id`
     */
    public function unidadesApartadasPorActivosEnAlmacen(array $activoIds, int $almacenId, ?string $excluirToken = null): array
    {
        if ($activoIds === []) {
            return [];
        }

        return RenglonReserva::query()
            ->join('unidades_activo', 'unidades_activo.id', '=', 'reservas_inventario_renglones.unidad_activo_id')
            ->whereIn('unidades_activo.activo_id', $activoIds)
            ->where('unidades_activo.almacen_id', $almacenId)
            ->whereHas('reserva', fn (Builder $q) => $this->filtrarActivaExcluyendoPropia($q, $excluirToken))
            ->selectRaw('unidades_activo.activo_id as activo_id, count(*) as total')
            ->groupBy('unidades_activo.activo_id')
            ->pluck('total', 'activo_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * ¿Alguna reserva ACTIVA de otro borrador ya apartó esta unidad
     * concreta? Aplica igual a Entrega (unidad de almacén) y a Devolución
     * (unidad actualmente asignada que se va a devolver): una unidad física
     * sólo puede estar en UNA reserva activa a la vez, sin importar el tipo.
     */
    public function unidadApartadaPorOtro(int $unidadActivoId, ?string $excluirToken = null): bool
    {
        return RenglonReserva::query()
            ->where('unidad_activo_id', $unidadActivoId)
            ->whereHas('reserva', fn (Builder $q) => $this->filtrarActivaExcluyendoPropia($q, $excluirToken))
            ->exists();
    }

    /**
     * IDs de `UnidadActivo` de un activo+almacén que otra reserva ACTIVA ya
     * apartó (de cualquier tipo — Entrega o Devolución: una unidad física
     * sólo puede estar en UNA reserva a la vez). Usado para excluirlas antes
     * de elegir candidatas nuevas (`ServicioUnidadesActivo::candidatosParaReserva()`).
     *
     * @return array<int, int>
     */
    public function unidadesApartadasPorOtros(int $activoId, int $almacenId, ?string $excluirToken = null): array
    {
        return RenglonReserva::query()
            ->whereNotNull('unidad_activo_id')
            ->whereHas('unidadActivo', fn (Builder $q) => $q->where('activo_id', $activoId)->where('almacen_id', $almacenId))
            ->whereHas('reserva', fn (Builder $q) => $this->filtrarActivaExcluyendoPropia($q, $excluirToken))
            ->pluck('unidad_activo_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Bloquea (`lockForUpdate`) y devuelve la cabecera de una reserva activa
     * por token, validando que pertenezca al usuario y sea del tipo
     * esperado. Debe llamarse dentro de una transacción. Lanza una excepción
     * de negocio (nunca null silencioso) si el token no existe, es de otro
     * usuario, es de otro tipo, o ya venció/se liberó/se consumió — el
     * llamador decide si eso es motivo para rebotar el paso 2 del wizard.
     */
    public function bloquearActivaPorToken(string $token, int $userId, TipoReserva $tipo): Reserva
    {
        $reserva = Reserva::query()->where('token', $token)->lockForUpdate()->first();

        if ($reserva === null || $reserva->user_id !== $userId || $reserva->tipo !== $tipo) {
            throw new ExcepcionDeNegocioSimple('Esa reserva no existe o no te pertenece.');
        }

        if (! $reserva->estaActiva()) {
            throw new ExcepcionDeNegocioSimple('Tu reserva venció. Actualizamos las existencias disponibles; revisa los artículos antes de continuar.');
        }

        return $reserva;
    }

    /**
     * Cabecera de reserva para un token dado: reutiliza la fila si ya existe
     * y sigue activa (recalcula sus líneas desde cero), o crea una nueva.
     * Bloquea la fila para serializar dos recálculos concurrentes del MISMO
     * borrador (p. ej. doble click). Debe llamarse dentro de una transacción.
     */
    public function obtenerOCrearCabecera(
        string $token,
        TipoReserva $tipo,
        int $userId,
        int $empresaId,
        ?int $almacenId,
        ?int $colaboradorId,
        ?int $entregaId,
    ): Reserva {
        $reserva = Reserva::query()->where('token', $token)->lockForUpdate()->first();

        if ($reserva !== null && ($reserva->user_id !== $userId || $reserva->tipo !== $tipo)) {
            throw new ExcepcionDeNegocioSimple('Esa reserva no existe o no te pertenece.');
        }

        $expiraEn = now()->addMinutes(Reserva::DURACION_MINUTOS);

        if ($reserva === null) {
            $reserva = Reserva::query()->create([
                'token' => $token,
                'tipo' => $tipo,
                'user_id' => $userId,
                'empresa_id' => $empresaId,
                'almacen_id' => $almacenId,
                'colaborador_id' => $colaboradorId,
                'entrega_uniforme_id' => $entregaId,
                'expira_en' => $expiraEn,
            ]);

            return Reserva::query()->whereKey($reserva->getKey())->lockForUpdate()->firstOrFail();
        }

        $reserva->update([
            'empresa_id' => $empresaId,
            'almacen_id' => $almacenId,
            'colaborador_id' => $colaboradorId,
            'entrega_uniforme_id' => $entregaId,
            'expira_en' => $expiraEn,
            'consumida_en' => null,
            'liberada_en' => null,
        ]);

        $reserva->renglones()->delete();

        return $reserva;
    }

    public function liberar(string $token, int $userId): void
    {
        DB::transaction(function () use ($token, $userId): void {
            $reserva = Reserva::query()->where('token', $token)->where('user_id', $userId)->lockForUpdate()->first();

            if ($reserva === null || ! $reserva->estaActiva()) {
                return;
            }

            $reserva->update(['liberada_en' => now()]);
        });
    }

    public function extender(string $token, int $userId, TipoReserva $tipo): Reserva
    {
        return DB::transaction(function () use ($token, $userId, $tipo): Reserva {
            $reserva = $this->bloquearActivaPorToken($token, $userId, $tipo);
            $reserva->update(['expira_en' => now()->addMinutes(Reserva::DURACION_MINUTOS)]);

            return $reserva;
        });
    }
}
