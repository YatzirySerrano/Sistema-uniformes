<?php

namespace App\Http\Controllers\Concerns;

use Closure;
use Illuminate\Database\QueryException;

/**
 * Última defensa contra un `Duplicate entry` inesperado en un código
 * autogenerado: el contador atómico + la reconciliación
 * (`ReconciliaSecuenciaCodigo`) ya deberían impedirlo siempre, pero si algo
 * externo al generador insertó ese código exacto entre la reserva del
 * consecutivo y el INSERT real (p. ej. otro proceso restaurando datos en ese
 * instante), reintenta con un código nuevo en vez de dejar que el usuario
 * vea un 500. Nunca oculta un error de integridad que no sea por el código.
 */
trait CreaConCodigoUnico
{
    /**
     * @template T
     *
     * @param  Closure(): T  $crear  Arma el modelo con un código NUEVO (generado dentro del closure) y lo persiste.
     * @return T
     */
    protected function crearConCodigoUnico(Closure $crear, int $intentos = 3): mixed
    {
        $intento = 1;

        while (true) {
            try {
                return $crear();
            } catch (QueryException $e) {
                if ($intento >= $intentos || $e->getCode() !== '23000') {
                    throw $e;
                }

                $intento++;
            }
        }
    }
}
