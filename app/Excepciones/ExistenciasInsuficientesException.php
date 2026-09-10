<?php

namespace App\Excepciones;

class ExistenciasInsuficientesException extends ExcepcionDeNegocio
{
    private function __construct(
        string $message,
        public readonly string $activo,
        public readonly string $talla,
        public readonly string $almacen,
        public readonly int $disponible,
        public readonly int $solicitado,
    ) {
        parent::__construct($message);
    }

    public static function para(string $activo, string $talla, string $almacen, int $disponible, int $solicitado): self
    {
        return new self(
            sprintf(
                'No hay existencias suficientes de %s (variante %s) en el almacén %s. Disponibles: %d, solicitadas: %d.',
                $activo,
                $talla,
                $almacen,
                $disponible,
                $solicitado,
            ),
            $activo,
            $talla,
            $almacen,
            $disponible,
            $solicitado,
        );
    }
}
