<?php

namespace App\Http\Requests\Concerns;

/**
 * Utilidades de normalización de entrada compartidas por los Form Requests.
 * Evitan duplicar la limpieza de texto y de teléfonos en cada módulo.
 */
trait NormalizaEntrada
{
    /**
     * Recorta espacios y devuelve null si el valor queda vacío o no es texto.
     */
    protected function limpiar(mixed $valor): ?string
    {
        if (! is_string($valor) && ! is_numeric($valor)) {
            return null;
        }

        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }

    /**
     * Deja sólo los dígitos de un valor (teléfonos). Null si no queda nada.
     */
    protected function soloDigitos(mixed $valor): ?string
    {
        $texto = $this->limpiar($valor);

        if ($texto === null) {
            return null;
        }

        $digitos = preg_replace('/\D+/', '', $texto) ?? '';

        return $digitos === '' ? null : $digitos;
    }
}
