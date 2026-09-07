<?php

namespace App\Soporte;

use App\Models\SecuenciaCodigoGlobal;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Genera códigos consecutivos, únicos y race-safe para catálogos GLOBALES de
 * plataforma (sin dimensión de empresa: Almacén, Tipo de activo…). Mismo
 * principio que `App\Soporte\ServicioGeneradorCodigos` (lock de la fila
 * contador dentro de una transacción antes de incrementar, con
 * reconciliación contra un `$semilla` real en cada llamada — no sólo al
 * crear la fila — para blindarse contra secuencias atrasadas por seeders,
 * restauraciones o códigos capturados a mano antes de ser autogenerados),
 * pero respaldado por `secuencias_codigo_globales` (una fila por ámbito, no
 * por empresa+ámbito) para no forzar una FK de empresa nula en una tabla
 * pensada para contadores por empresa.
 */
class ServicioGeneradorCodigosGlobal
{
    public function siguiente(string $ambito, string $prefijo, int $digitos = 4, ?Closure $semilla = null): string
    {
        return sprintf('%s-%0'.$digitos.'d', $prefijo, $this->incrementar($ambito, $semilla));
    }

    /**
     * Lee cuál sería el siguiente código con prefijo SIN reservarlo (no crea
     * ni bloquea la fila) — sólo para previsualizaciones no autoritativas.
     */
    public function siguienteAproximado(string $ambito, string $prefijo, int $digitos = 4, ?Closure $semilla = null): string
    {
        return sprintf('%s-%0'.$digitos.'d', $prefijo, $this->aproximado($ambito, $semilla));
    }

    /**
     * Reserva atómicamente el siguiente consecutivo de un ámbito, sin
     * prefijo ni formato — para códigos que no siguen el patrón
     * "PREFIJO-0000" (p. ej. el código de Empresa, "BASE01").
     */
    public function siguienteNumero(string $ambito, ?Closure $semilla = null): int
    {
        return $this->incrementar($ambito, $semilla);
    }

    /**
     * Lee cuál sería el siguiente consecutivo sin formato, SIN reservarlo.
     */
    public function siguienteNumeroAproximado(string $ambito, ?Closure $semilla = null): int
    {
        return $this->aproximado($ambito, $semilla);
    }

    private function incrementar(string $ambito, ?Closure $semilla): int
    {
        return DB::transaction(function () use ($ambito, $semilla): int {
            $secuencia = SecuenciaCodigoGlobal::query()
                ->where('ambito', $ambito)
                ->lockForUpdate()
                ->first();

            if ($secuencia === null) {
                $secuencia = SecuenciaCodigoGlobal::query()->create([
                    'ambito' => $ambito,
                    'ultimo_valor' => 0,
                ]);
                $secuencia = SecuenciaCodigoGlobal::query()
                    ->whereKey($secuencia->getKey())
                    ->lockForUpdate()
                    ->first();
            }

            $base = $semilla !== null
                ? max($secuencia->ultimo_valor, $semilla())
                : $secuencia->ultimo_valor;

            $siguiente = $base + 1;
            $secuencia->update(['ultimo_valor' => $siguiente]);

            return $siguiente;
        });
    }

    private function aproximado(string $ambito, ?Closure $semilla): int
    {
        $ultimoValor = (int) (SecuenciaCodigoGlobal::query()
            ->where('ambito', $ambito)
            ->value('ultimo_valor') ?? 0);

        if ($semilla !== null) {
            $ultimoValor = max($ultimoValor, $semilla());
        }

        return $ultimoValor + 1;
    }
}
