<?php

namespace App\Excepciones;

use RuntimeException;

/**
 * Marcador interno de `ServicioImportacionColaboradores`: nunca se muestra al
 * usuario. Se lanza a propósito dentro de la transacción de análisis (o
 * cuando la confirmación detecta errores/duplicados de último momento) para
 * forzar el rollback sin persistir nada — incluye el consecutivo reservado
 * por `GeneradorNumeroEmpleado`, que así nunca se consume en un análisis.
 * Mismo patrón que `SimulacionImportacionMaestra`.
 */
final class SimulacionImportacionColaboradores extends RuntimeException {}
