<?php

namespace App\Enums;

/**
 * Robo / pérdida de un activo POR CANTIDAD bajo custodia de un colaborador
 * (ver `App\Models\IncidenciaCustodia`). Mismo vocabulario/valores que
 * `CondicionUnidadActivo::Perdido`/`Robado` para seguimiento individual —
 * dos ejes distintos del mismo concepto de dominio, nunca el mismo enum
 * (uno vive en la unidad, el otro en un evento de custodia aparte).
 */
enum TipoIncidenciaCustodia: string
{
    case Robado = 'robado';
    case Perdido = 'perdido';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Robado => 'Robado',
            self::Perdido => 'Perdido',
        };
    }
}
