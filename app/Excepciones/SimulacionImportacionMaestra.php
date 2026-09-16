<?php

namespace App\Excepciones;

use RuntimeException;

/**
 * Marcador interno de `ServicioImportacionMaestra`: nunca se muestra al
 * usuario. Se lanza a propósito dentro de la transacción de prevalidación
 * (o cuando la confirmación detecta errores de último momento) para forzar
 * el rollback sin persistir nada, reutilizando exactamente la misma lógica
 * de resolución/creación que la confirmación real.
 */
final class SimulacionImportacionMaestra extends RuntimeException {}
