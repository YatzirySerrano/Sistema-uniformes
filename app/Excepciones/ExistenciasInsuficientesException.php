<?php

namespace App\Excepciones;

class ExistenciasInsuficientesException extends ExcepcionDeNegocio
{
    public static function para(string $prenda, string $talla, string $sucursal, int $disponible, int $solicitado): self
    {
        return new self(sprintf(
            'No hay existencias suficientes de %s talla %s en la sucursal %s. Disponibles: %d, solicitadas: %d.',
            $prenda,
            $talla,
            $sucursal,
            $disponible,
            $solicitado,
        ));
    }
}
