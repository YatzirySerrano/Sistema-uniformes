<?php

namespace App\Excepciones;

class ExistenciasInsuficientesException extends ExcepcionDeNegocio
{
    public static function para(string $activo, string $talla, string $sucursal, int $disponible, int $solicitado): self
    {
        return new self(sprintf(
            'No hay existencias suficientes de %s talla %s en la sucursal %s. Disponibles: %d, solicitadas: %d.',
            $activo,
            $talla,
            $sucursal,
            $disponible,
            $solicitado,
        ));
    }
}
