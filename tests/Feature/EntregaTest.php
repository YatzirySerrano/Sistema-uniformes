<?php

use App\Acciones\CrearEntregaUniforme;
use App\Enums\EstadoEntrega;
use App\Enums\TipoMovimiento;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\Colaborador;
use App\Models\EntregaUniforme;
use App\Models\MovimientoInventario;
use App\Models\SaldoInventario;
use App\Models\User;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;

beforeEach(function () {
    $this->datos = escenarioMultiempresa();
    $this->encargado = User::factory()->create();
    $inventario = app(ServicioInventario::class);
    $inventario->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id,
        sucursalId: $this->datos['sucursalA']->id,
        activoId: $this->datos['activoA']->id,
        tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial,
        cantidad: 20,
    ));
    $this->accion = app(CrearEntregaUniforme::class);
});

it('crea la entrega con sus items, descuenta el inventario y registra el movimiento', function () {
    $entrega = $this->accion->ejecutar(
        $this->datos['empresaA']->id,
        $this->datos['sucursalA']->id,
        $this->datos['colaboradorA']->id,
        1,
        now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 3]],
    );

    expect($entrega->estado)->toBe(EstadoEntrega::PendienteFirma)
        ->and($entrega->folio)->toStartWith('ENT-')
        ->and($entrega->detalles)->toHaveCount(1)
        ->and($entrega->detalles->first()->activo_nombre_snapshot)->toBe('Camisa')
        ->and(SaldoInventario::first()->cantidad)->toBe(17)
        ->and(MovimientoInventario::where('tipo', TipoMovimiento::Entrega->value)->count())->toBe(1);
});

it('no registra nada si algún item no tiene existencias suficientes (transacción atómica)', function () {
    try {
        $this->accion->ejecutar(
            $this->datos['empresaA']->id,
            $this->datos['sucursalA']->id,
            $this->datos['colaboradorA']->id,
            $this->encargado->id,
            now()->toDateString(),
            [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 999]],
        );
    } catch (Throwable) {
        // esperado
    }

    expect(EntregaUniforme::count())->toBe(0)
        ->and(SaldoInventario::first()->cantidad)->toBe(20);
});

it('rechaza entregar a un colaborador de otra empresa', function () {
    $colaboradorB = Colaborador::factory()->for($this->datos['empresaB'])->for($this->datos['sucursalB'])->create();

    $this->accion->ejecutar(
        $this->datos['empresaA']->id,
        $this->datos['sucursalA']->id,
        $colaboradorB->id,
        $this->encargado->id,
        now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 1]],
    );
})->throws(ExcepcionDeNegocioSimple::class);
