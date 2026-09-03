<?php

namespace App\Servicios\DTO;

use App\Enums\TipoMovimiento;

/**
 * Datos de entrada para registrar un movimiento de inventario a través de
 * ServicioInventario. La dimensión del saldo es el ALMACÉN; `sucursalId` sólo
 * se guarda como procedencia/contexto en el movimiento (p. ej. la sucursal del
 * colaborador que recibió una entrega). La dirección se deriva del tipo.
 */
final readonly class MovimientoInventarioDatos
{
    public function __construct(
        public int $empresaId,
        public int $almacenId,
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
        public ?int $sucursalId = null,
    ) {}
}
