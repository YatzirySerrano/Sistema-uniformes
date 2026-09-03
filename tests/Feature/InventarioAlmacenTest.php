<?php

use App\Acciones\AjustarInventario;
use App\Acciones\RegistrarEntradaInventario;
use App\Enums\RolSistema;
use App\Excepciones\ExcepcionDeNegocio;
use App\Models\Activo;
use App\Models\MovimientoInventario;
use App\Models\SaldoInventario;
use App\Soporte\ContextoEmpresa;

beforeEach(function () {
    $this->datos = escenarioMultiempresa();
});

it('registra una entrada de inventario contra el almacén y crea el saldo', function () {
    app(RegistrarEntradaInventario::class)->ejecutar(
        $this->datos['empresaA']->id,
        $this->datos['almacenA']->id,
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 15]],
        'Compra inicial',
        null,
    );

    $saldo = SaldoInventario::query()->where('almacen_id', $this->datos['almacenA']->id)->first();
    expect($saldo->cantidad)->toBe(15)
        ->and($saldo->sucursal_id)->toBeNull();
});

it('un ajuste fija la existencia del almacén y exige motivo', function () {
    app(RegistrarEntradaInventario::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenA']->id,
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 10]],
        'Alta', null,
    );

    app(AjustarInventario::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenA']->id,
        $this->datos['activoA']->id, $this->datos['tallaA']->id, 4, 'Conteo físico', null,
    );

    expect(SaldoInventario::query()->where('almacen_id', $this->datos['almacenA']->id)->value('cantidad'))->toBe(4);

    expect(fn () => app(AjustarInventario::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenA']->id,
        $this->datos['activoA']->id, $this->datos['tallaA']->id, 2, '   ', null,
    ))->toThrow(ExcepcionDeNegocio::class);
});

it('los movimientos son append-only: cada operación agrega una fila', function () {
    app(RegistrarEntradaInventario::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenA']->id,
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 5]],
        'Uno', null,
    );
    app(RegistrarEntradaInventario::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenA']->id,
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 3]],
        'Dos', null,
    );

    expect(MovimientoInventario::query()->where('almacen_id', $this->datos['almacenA']->id)->count())->toBe(2);
});

it('un almacén desactivado no admite entradas', function () {
    $this->datos['almacenA']->update(['activo' => false]);

    expect(fn () => app(RegistrarEntradaInventario::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenA']->id,
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 1]],
        'X', null,
    ))->toThrow(ExcepcionDeNegocio::class);
});

it('rechaza una entrada cuyo almacén pertenece a otra empresa', function () {
    expect(fn () => app(RegistrarEntradaInventario::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenB']->id,
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 1]],
        'X', null,
    ))->toThrow(ExcepcionDeNegocio::class);
});

it('rechaza una entrada con un activo o talla de otra empresa', function () {
    $activoAjeno = Activo::factory()->for($this->datos['empresaB'])->create();

    expect(fn () => app(RegistrarEntradaInventario::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenA']->id,
        [['activo_id' => $activoAjeno->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 1]],
        'X', null,
    ))->toThrow(ExcepcionDeNegocio::class);
});

it('la pantalla de inventario sólo muestra saldos por almacén (no legacy) y filtra por almacén', function () {
    $empresa = $this->datos['empresaA'];
    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    SaldoInventario::factory()->create([
        'empresa_id' => $empresa->id, 'almacen_id' => $this->datos['almacenA']->id, 'sucursal_id' => null,
        'activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 7,
    ]);
    SaldoInventario::factory()->legacy($this->datos['sucursalA'])->create([
        'empresa_id' => $empresa->id, 'activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 99,
    ]);

    $this->actingAs($admin)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->get('/inventario')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventario/Index')
            ->where('saldos.data.0.cantidad', 7)
            ->where('saldos.data.0.almacen', 'Almacén A')
            ->where('saldosLegacyPendientes', 1)
        );
});

it('un ajuste absoluto por HTTP requiere el permiso y valida el almacén de la empresa activa', function () {
    $empresa = $this->datos['empresaA'];
    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $this->actingAs($admin)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->post('/inventario/ajuste', [
            'almacen_id' => $this->datos['almacenB']->id,
            'activo_id' => $this->datos['activoA']->id,
            'talla_id' => $this->datos['tallaA']->id,
            'existencia_objetivo' => 5,
            'motivo' => 'Prueba',
        ])
        ->assertForbidden();
});
