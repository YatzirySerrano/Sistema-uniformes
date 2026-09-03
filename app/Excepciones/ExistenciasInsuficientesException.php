<?php

namespace App\Excepciones;

class ExistenciasInsuficientesException extends ExcepcionDeNegocio
{
    public static function para(string $activo, string $talla, string $almacen, int $disponible, int $solicitado): self
    {
        return new self(sprintf(
            'No hay existencias suficientes de %s (variante %s) en el almacén %s. Disponibles: %d, solicitadas: %d.',
            $activo,
            $talla,
            $almacen,
            $disponible,
            $solicitado,
        ));
    }
}
