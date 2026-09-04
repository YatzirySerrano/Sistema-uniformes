<?php

use App\Acciones\CrearEntregaUniforme;
use App\Enums\EstadoEntrega;
use App\Enums\EstadoUnidadActivo;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\Activo;
use App\Models\Colaborador;
use App\Models\Conjunto;
use App\Models\EntregaUniforme;
use App\Models\MovimientoInventario;
use App\Models\SaldoInventario;
use App\Models\UnidadActivo;
use App\Models\User;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;

beforeEach(function () {
    $this->datos = escenarioMultiempresa();
    $this->encargado = User::factory()->create();
    $inventario = app(ServicioInventario::class);
    $inventario->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id,
        almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id,
        tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial,
        cantidad: 20,
    ));
    $this->accion = app(CrearEntregaUniforme::class);
});

it('crea la entrega con sus activos, descuenta el inventario y registra el movimiento', function () {
    $entrega = $this->accion->ejecutar(
        $this->datos['colaboradorA']->id,
        $this->datos['almacenA']->id,
        $this->encargado->id,
        now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 3]],
        [],
        [],
    );

    expect($entrega->estado)->toBe(EstadoEntrega::PendienteFirma)
        ->and($entrega->folio)->toStartWith('ENT-')
        ->and($entrega->almacen_id)->toBe($this->datos['almacenA']->id)
        ->and($entrega->detalles)->toHaveCount(1)
        ->and($entrega->detalles->first()->activo_nombre_snapshot)->toBe('Camisa')
        ->and(SaldoInventario::first()->cantidad)->toBe(17)
        ->and(MovimientoInventario::where('tipo', TipoMovimiento::Entrega->value)->count())->toBe(1);
});

it('no registra nada si algún activo no tiene existencias suficientes (transacción atómica)', function () {
    try {
        $this->accion->ejecutar(
            $this->datos['colaboradorA']->id,
            $this->datos['almacenA']->id,
            $this->encargado->id,
            now()->toDateString(),
            [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 999]],
            [],
            [],
        );
    } catch (Throwable) {
        // esperado
    }

    expect(EntregaUniforme::count())->toBe(0)
        ->and(SaldoInventario::first()->cantidad)->toBe(20);
});

it('rechaza entregar a un colaborador de otra empresa que la del almacén elegido', function () {
    $colaboradorB = Colaborador::factory()->for($this->datos['empresaB'])->for($this->datos['sucursalB'])->create();

    $this->accion->ejecutar(
        $colaboradorB->id,
        $this->datos['almacenA']->id,
        $this->encargado->id,
        now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 1]],
        [],
        [],
    );
})->throws(ExcepcionDeNegocioSimple::class);

it('entrega una unidad de seguimiento individual, la asigna al colaborador y descuenta de disponibles (sin tocar saldos)', function () {
    $activoIndividual = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create();
    $unidad = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($activoIndividual)->for($this->datos['almacenA'])->create();

    $entrega = $this->accion->ejecutar(
        $this->datos['colaboradorA']->id,
        $this->datos['almacenA']->id,
        $this->encargado->id,
        now()->toDateString(),
        [],
        [['unidad_activo_id' => $unidad->id]],
        [],
    );

    $unidad->refresh();
    expect($entrega->detalles)->toHaveCount(1)
        ->and($entrega->detalles->first()->unidad_activo_id)->toBe($unidad->id)
        ->and($unidad->estado)->toBe(EstadoUnidadActivo::Asignada)
        ->and($unidad->colaborador_id)->toBe($this->datos['colaboradorA']->id)
        ->and($unidad->esEntregable())->toBeFalse();
});

it('rechaza entregar dos veces la misma unidad ya asignada', function () {
    $activoIndividual = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create();
    $unidad = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($activoIndividual)->for($this->datos['almacenA'])->asignada()->create();

    expect(fn () => $this->accion->ejecutar(
        $this->datos['colaboradorA']->id,
        $this->datos['almacenA']->id,
        $this->encargado->id,
        now()->toDateString(),
        [],
        [['unidad_activo_id' => $unidad->id]],
        [],
    ))->toThrow(ExcepcionDeNegocioSimple::class);
});

it('expande un conjunto a sus componentes reales y descuenta el stock de cada uno, no un saldo propio', function () {
    $pantalon = Activo::factory()->for($this->datos['empresaA'])->create(['nombre' => 'Pantalón']);
    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id,
        almacenId: $this->datos['almacenA']->id,
        activoId: $pantalon->id,
        tallaId: null,
        tipo: TipoMovimiento::Inicial,
        cantidad: 10,
    ));

    $conjunto = Conjunto::factory()->for($this->datos['empresaA'])->create();
    $conjunto->componentes()->create(['activo_id' => $this->datos['activoA']->id, 'cantidad_requerida' => 1, 'talla_id' => $this->datos['tallaA']->id]);
    $conjunto->componentes()->create(['activo_id' => $pantalon->id, 'cantidad_requerida' => 1]);

    $entrega = $this->accion->ejecutar(
        $this->datos['colaboradorA']->id,
        $this->datos['almacenA']->id,
        $this->encargado->id,
        now()->toDateString(),
        [],
        [],
        [['conjunto_id' => $conjunto->id, 'cantidad' => 1]],
    );

    expect($entrega->detalles)->toHaveCount(2)
        ->and($entrega->detalles->every(fn ($d) => $d->conjunto_id === $conjunto->id))->toBeTrue();

    $saldoCamisa = SaldoInventario::where('activo_id', $this->datos['activoA']->id)->first();
    $saldoPantalon = SaldoInventario::where('activo_id', $pantalon->id)->first();
    expect($saldoCamisa->cantidad)->toBe(19)
        ->and($saldoPantalon->cantidad)->toBe(9);
});

it('rechaza un conjunto sin disponibilidad suficiente en el almacén elegido', function () {
    $conjunto = Conjunto::factory()->for($this->datos['empresaA'])->create();
    $conjunto->componentes()->create(['activo_id' => $this->datos['activoA']->id, 'cantidad_requerida' => 100, 'talla_id' => $this->datos['tallaA']->id]);

    expect(fn () => $this->accion->ejecutar(
        $this->datos['colaboradorA']->id,
        $this->datos['almacenA']->id,
        $this->encargado->id,
        now()->toDateString(),
        [],
        [],
        [['conjunto_id' => $conjunto->id, 'cantidad' => 1]],
    ))->toThrow(ExcepcionDeNegocioSimple::class);

    expect(EntregaUniforme::count())->toBe(0);
});

it('rechaza un almacén que no abastece a la empresa del colaborador', function () {
    expect(fn () => $this->accion->ejecutar(
        $this->datos['colaboradorA']->id,
        $this->datos['almacenB']->id,
        $this->encargado->id,
        now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 1]],
        [],
        [],
    ))->toThrow(ExcepcionDeNegocioSimple::class);
});

it('la petición HTTP de creación busca la entrega y expone empresa, almacén y renglones con unidad/conjunto', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);

    $entrega = $this->accion->ejecutar(
        $this->datos['colaboradorA']->id,
        $this->datos['almacenA']->id,
        $this->encargado->id,
        now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 2]],
        [],
        [],
    );

    $this->actingAs($admin)
        ->get("/entregas/{$entrega->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Entregas/Detalle')
            ->where('entrega.almacen', 'Almacén A')
            ->where('entrega.items.0.cantidad', 2),
        );
});
