<?php

use App\Enums\TipoMovimiento;
use App\Excepciones\ExistenciasInsuficientesException;
use App\Models\MovimientoInventario;
use App\Models\SaldoInventario;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;

beforeEach(function () {
    $this->datos = escenarioMultiempresa();
    $this->inventario = app(ServicioInventario::class);
});

function movimiento(array $datos, TipoMovimiento $tipo, int $cantidad): MovimientoInventarioDatos
{
    return new MovimientoInventarioDatos(
        empresaId: $datos['empresaA']->id,
        sucursalId: $datos['sucursalA']->id,
        activoId: $datos['activoA']->id,
        tallaId: $datos['tallaA']->id,
        tipo: $tipo,
        cantidad: $cantidad,
    );
}

it('mantiene coherencia entre el saldo y la existencia resultante del movimiento', function () {
    $this->inventario->registrarMovimiento(movimiento($this->datos, TipoMovimiento::Inicial, 10));
    $this->inventario->registrarMovimiento(movimiento($this->datos, TipoMovimiento::Entrada, 5));
    $mov = $this->inventario->registrarMovimiento(movimiento($this->datos, TipoMovimiento::Entrega, 4));

    $saldo = SaldoInventario::first();

    expect($saldo->cantidad)->toBe(11)
        ->and($mov->existencia_anterior)->toBe(15)
        ->and($mov->existencia_resultante)->toBe(11)
        ->and($mov->direccion->value)->toBe('salida');
});

it('no permite dejar el inventario en negativo', function () {
    $this->inventario->registrarMovimiento(movimiento($this->datos, TipoMovimiento::Inicial, 3));

    $this->inventario->registrarMovimiento(movimiento($this->datos, TipoMovimiento::Entrega, 5));
})->throws(ExistenciasInsuficientesException::class);

it('no crea el movimiento de salida cuando el saldo es insuficiente (rollback)', function () {
    $this->inventario->registrarMovimiento(movimiento($this->datos, TipoMovimiento::Inicial, 3));

    try {
        $this->inventario->registrarMovimiento(movimiento($this->datos, TipoMovimiento::Entrega, 5));
    } catch (ExistenciasInsuficientesException) {
        // esperado
    }

    expect(SaldoInventario::first()->cantidad)->toBe(3)
        ->and(MovimientoInventario::where('tipo', TipoMovimiento::Entrega->value)->count())->toBe(0);
});

it('un ajuste absoluto genera el movimiento con la diferencia y exige motivo', function () {
    $this->inventario->registrarMovimiento(movimiento($this->datos, TipoMovimiento::Inicial, 10));

    $mov = $this->inventario->fijarExistencia(
        $this->datos['empresaA']->id,
        $this->datos['sucursalA']->id,
        $this->datos['activoA']->id,
        $this->datos['tallaA']->id,
        4,
        'Merma detectada en conteo físico',
        null,
    );

    expect(SaldoInventario::first()->cantidad)->toBe(4)
        ->and($mov->tipo)->toBe(TipoMovimiento::AjusteSalida)
        ->and($mov->cantidad)->toBe(6);
});
