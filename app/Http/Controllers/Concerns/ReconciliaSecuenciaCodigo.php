<?php

namespace App\Http\Controllers\Concerns;

/**
 * Helper compartido para reconciliar una secuencia de código
 * (`ServicioGeneradorCodigos`/`ServicioGeneradorCodigosGlobal`) contra los
 * registros REALES de una tabla: calcula el mayor sufijo numérico entre
 * códigos que ya usan el prefijo esperado (p. ej. "ALM-0003" con prefijo
 * "ALM-" → 3). El generador usa este valor como piso dentro del `lockForUpdate()`,
 * así que una secuencia atrasada (seeder, restauración, captura manual
 * histórica) nunca vuelve a emitir un código ya usado.
 */
trait ReconciliaSecuenciaCodigo
{
    /**
     * @param  iterable<string>  $codigos
     */
    protected function maximoSufijo(iterable $codigos, string $prefijo): int
    {
        $maximo = 0;

        foreach ($codigos as $codigo) {
            if (str_starts_with($codigo, $prefijo) && preg_match('/(\d+)$/', $codigo, $coincidencia) === 1) {
                $maximo = max($maximo, (int) $coincidencia[1]);
            }
        }

        return $maximo;
    }
}
