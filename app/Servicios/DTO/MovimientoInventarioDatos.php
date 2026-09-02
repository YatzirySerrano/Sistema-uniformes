<?php

namespace App\Servicios\DTO;

use App\Enums\TipoMovimiento;

/**
 * Datos de entrada para registrar un movimiento de inventario a través de
 * ServicioInventario. La dirección se deriva del tipo.
 */
final readonly class MovimientoInventarioDatos
{
    public function __construct(
        public int $empresaId,
        public int $sucursalId,
        public int $activoId,
        public int $tallaId,
        public TipoMovimiento $tipo,
        public int $cantidad,
        public ?int $realizadoPor = null,
        public ?string $referenciaTipo = null,
        public ?int $referenciaId = null,
        public ?string $motivo = null,
        public ?string $notas = null,
        public bool $permitirNegativo = false,
    ) {}
}
