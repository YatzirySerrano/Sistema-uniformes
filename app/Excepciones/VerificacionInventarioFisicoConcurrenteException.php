<?php

namespace App\Excepciones;

use App\Models\InventarioFisicoExistencia;
use App\Models\InventarioFisicoUnidad;

/**
 * Otro encargado ya verificó (o cambió) el mismo renglón de una ronda de
 * inventario físico mientras esta pantalla mostraba un estado anterior. Gana
 * la primera verificación válida: nada se sobrescribe en silencio. El
 * controlador responde 409 con el mensaje (quién y cuándo) y la fila REAL
 * actual para que la pantalla se refresque.
 */
class VerificacionInventarioFisicoConcurrenteException extends ExcepcionDeNegocio
{
    public function __construct(string $message, public readonly InventarioFisicoUnidad|InventarioFisicoExistencia $fila)
    {
        parent::__construct($message);
    }
}
