<?php

namespace App\Soporte;

use App\Models\Empresa;
use App\Models\SecuenciaCodigo;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Genera códigos internos únicos, race-safe y permanentes para catálogos
 * acotados a una empresa (unidades de seguimiento individual, sucursales,
 * áreas, activos…). Nunca `MAX(id)+1` / `count()+1` sin protección: la fila
 * de `secuencias_codigo` se bloquea con `lockForUpdate()` dentro de una
 * transacción antes de incrementar, así que dos altas concurrentes de la
 * misma empresa nunca obtienen el mismo número.
 *
 * Reconciliación: cada llamada admite un `$semilla` opcional (el llamador
 * calcula el mayor consecutivo REAL ya usado en la tabla destino) que se
 * compara contra `ultimo_valor` DENTRO del mismo lock antes de incrementar —
 * nunca sólo al crear la fila la primera vez. Esto blinda contra secuencias
 * atrasadas por seeders, restauraciones de BD o códigos capturados a mano
 * antes de que el campo se volviera autogenerado: si la tabla ya tiene
 * "ALM-0003" pero el contador quedó en 2, la siguiente llamada reconcilia a 3
 * y emite "ALM-0004", nunca repite "ALM-0003".
 *
 * Para catálogos GLOBALES de plataforma (sin dimensión de empresa, p. ej.
 * Almacén o Tipo de activo) usa `App\Soporte\ServicioGeneradorCodigosGlobal`.
 */
class ServicioGeneradorCodigos
{
    /**
     * Código prefijado con `Empresa::codigo` (corto, ya único a nivel
     * plataforma) — nunca con el almacén ni con datos del Activo, para que el
     * código sea estable de por vida aunque la unidad cambie de almacén o el
     * Activo/Almacén/Empresa se renombren. Usado por unidades de seguimiento
     * individual.
     */
    public function siguiente(Empresa $empresa, string $ambito = 'unidad_activo', ?Closure $semilla = null): string
    {
        return sprintf('%s-%06d', $empresa->codigo, $this->incrementar($empresa->id, $ambito, $semilla));
    }

    /**
     * Código con un prefijo literal fijo (p. ej. "SUC", "ARE", "ACT"),
     * consecutivo dentro de la empresa. Mismo contador atómico que
     * `siguiente()`, sólo cambia el formato de salida.
     */
    public function siguienteConPrefijo(Empresa $empresa, string $ambito, string $prefijo, int $digitos = 4, ?Closure $semilla = null): string
    {
        return sprintf('%s-%0'.$digitos.'d', $prefijo, $this->incrementar($empresa->id, $ambito, $semilla));
    }

    /**
     * Lee cuál sería el siguiente código con prefijo (p. ej. "SUC-0004") SIN
     * reservar el consecutivo — sólo para previsualizaciones no
     * autoritativas. El valor definitivo se calcula de nuevo,
     * atómicamente, con `siguienteConPrefijo()`.
     */
    public function siguienteConPrefijoAproximado(Empresa $empresa, string $ambito, string $prefijo, int $digitos = 4, ?Closure $semilla = null): string
    {
        return sprintf('%s-%0'.$digitos.'d', $prefijo, $this->siguienteNumeroAproximado($empresa, $ambito, $semilla));
    }

    /**
     * Reserva atómicamente el siguiente consecutivo de un ámbito, sin
     * prefijo ni formato — el llamador arma el código final (p. ej.
     * iniciales + consecutivo para el número de empleado).
     */
    public function siguienteNumero(Empresa $empresa, string $ambito, ?Closure $semilla = null): int
    {
        return $this->incrementar($empresa->id, $ambito, $semilla);
    }

    /**
     * Lee cuál sería el siguiente consecutivo SIN reservarlo (no crea ni
     * bloquea la fila de `secuencias_codigo`) — únicamente para
     * previsualizaciones no autoritativas en el frontend. También reconcilia
     * contra `$semilla` (de forma no atómica: es sólo una vista previa). El
     * valor real se calcula de nuevo, atómicamente, con `siguienteNumero()`.
     */
    public function siguienteNumeroAproximado(Empresa $empresa, string $ambito, ?Closure $semilla = null): int
    {
        $ultimoValor = (int) (SecuenciaCodigo::query()
            ->where('empresa_id', $empresa->id)
            ->where('ambito', $ambito)
            ->value('ultimo_valor') ?? 0);

        if ($semilla !== null) {
            $ultimoValor = max($ultimoValor, $semilla());
        }

        return $ultimoValor + 1;
    }

    private function incrementar(int $empresaId, string $ambito, ?Closure $semilla = null): int
    {
        return DB::transaction(function () use ($empresaId, $ambito, $semilla): int {
            $secuencia = SecuenciaCodigo::query()
                ->where('empresa_id', $empresaId)
                ->where('ambito', $ambito)
                ->lockForUpdate()
                ->first();

            if ($secuencia === null) {
                $secuencia = SecuenciaCodigo::query()->create([
                    'empresa_id' => $empresaId,
                    'ambito' => $ambito,
                    'ultimo_valor' => 0,
                ]);
                $secuencia = SecuenciaCodigo::query()
                    ->whereKey($secuencia->getKey())
                    ->lockForUpdate()
                    ->first();
            }

            // Reconciliación dentro del lock: si la tabla real ya tiene un
            // consecutivo mayor al del contador (seeder, restauración,
            // captura manual histórica), arranca desde ahí — nunca desde un
            // contador atrasado que repetiría un código ya usado.
            $base = $semilla !== null
                ? max($secuencia->ultimo_valor, $semilla())
                : $secuencia->ultimo_valor;

            $siguiente = $base + 1;
            $secuencia->update(['ultimo_valor' => $siguiente]);

            return $siguiente;
        });
    }
}
