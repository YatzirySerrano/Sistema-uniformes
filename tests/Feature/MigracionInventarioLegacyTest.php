<?php

use App\Acciones\MigrarSaldosLegacyAAlmacen;
use App\Enums\RolSistema;
use App\Excepciones\ExcepcionDeNegocio;
use App\Models\MovimientoInventario;
use App\Models\SaldoInventario;
use App\Soporte\ContextoEmpresa;

beforeEach(function () {
    $this->datos = escenarioMultiempresa();
});

it('el asistente traslada al almacén los saldos legacy de una sucursal', function () {
    $d = $this->datos;

    SaldoInventario::factory()->legacy($d['sucursalA'])->create([
        'empresa_id' => $d['empresaA']->id, 'activo_id' => $d['activoA']->id,
        'talla_id' => $d['tallaA']->id, 'cantidad' => 20, 'minimo' => 5,
    ]);

    $migrados = app(MigrarSaldosLegacyAAlmacen::class)->ejecutar(
        $d['empresaA']->id, $d['sucursalA']->id, $d['almacenA']->id, null,
    );

    expect($migrados)->toBe(1);

    $saldo = SaldoInventario::query()->where('empresa_id', $d['empresaA']->id)->first();
    expect($saldo->almacen_id)->toBe($d['almacenA']->id)
        ->and($saldo->sucursal_id)->toBeNull()
        ->and($saldo->cantidad)->toBe(20)
        ->and($saldo->minimo)->toBe(5);

    $this->assertDatabaseHas('movimientos_inventario', [
        'almacen_id' => $d['almacenA']->id, 'tipo' => 'migracion_legacy', 'cantidad' => 20,
    ]);
    $this->assertDatabaseHas('bitacora_auditoria', ['modulo' => 'inventario', 'accion' => 'migracion_legacy']);
});

it('no duplica el saldo: si el almacén ya tenía existencias, suma y elimina la fila legacy', function () {
    $d = $this->datos;

    SaldoInventario::factory()->create([
        'empresa_id' => $d['empresaA']->id, 'almacen_id' => $d['almacenA']->id, 'sucursal_id' => null,
        'activo_id' => $d['activoA']->id, 'talla_id' => $d['tallaA']->id, 'cantidad' => 8, 'minimo' => 2,
    ]);
    SaldoInventario::factory()->legacy($d['sucursalA'])->create([
        'empresa_id' => $d['empresaA']->id, 'activo_id' => $d['activoA']->id,
        'talla_id' => $d['tallaA']->id, 'cantidad' => 12, 'minimo' => 5,
    ]);

    app(MigrarSaldosLegacyAAlmacen::class)->ejecutar($d['empresaA']->id, $d['sucursalA']->id, $d['almacenA']->id, null);

    $saldos = SaldoInventario::query()->where('empresa_id', $d['empresaA']->id)->get();
    expect($saldos)->toHaveCount(1)
        ->and($saldos->first()->cantidad)->toBe(20)
        ->and($saldos->first()->minimo)->toBe(5);
});

it('es idempotente: repetir la migración no vuelve a mover nada', function () {
    $d = $this->datos;

    SaldoInventario::factory()->legacy($d['sucursalA'])->create([
        'empresa_id' => $d['empresaA']->id, 'activo_id' => $d['activoA']->id,
        'talla_id' => $d['tallaA']->id, 'cantidad' => 10,
    ]);

    app(MigrarSaldosLegacyAAlmacen::class)->ejecutar($d['empresaA']->id, $d['sucursalA']->id, $d['almacenA']->id, null);
    $segunda = app(MigrarSaldosLegacyAAlmacen::class)->ejecutar($d['empresaA']->id, $d['sucursalA']->id, $d['almacenA']->id, null);

    expect($segunda)->toBe(0)
        ->and(MovimientoInventario::query()->where('tipo', 'migracion_legacy')->count())->toBe(1)
        ->and(SaldoInventario::query()->where('empresa_id', $d['empresaA']->id)->sum('cantidad'))->toBe(10);
});

it('rechaza migrar hacia un almacén de otra empresa', function () {
    $d = $this->datos;

    expect(fn () => app(MigrarSaldosLegacyAAlmacen::class)->ejecutar(
        $d['empresaA']->id, $d['sucursalA']->id, $d['almacenB']->id, null,
    ))->toThrow(ExcepcionDeNegocio::class);
});

it('el asistente lista las sucursales con saldos pendientes agrupados', function () {
    $d = $this->datos;
    $admin = usuarioCon(RolSistema::Administrador->value, [$d['empresaA']]);

    SaldoInventario::factory()->legacy($d['sucursalA'])->create([
        'empresa_id' => $d['empresaA']->id, 'activo_id' => $d['activoA']->id,
        'talla_id' => $d['tallaA']->id, 'cantidad' => 4,
    ]);

    $this->actingAs($admin)->withSession([ContextoEmpresa::SESSION_KEY => $d['empresaA']->id])
        ->get('/inventario/migracion')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventario/MigracionLegacy')
            ->where('pendientes.0.filas', 1)
            ->where('pendientes.0.unidades', 4)
        );
});

it('un supervisor no puede resolver la migración de inventario (403)', function () {
    $d = $this->datos;
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$d['empresaA']]);

    $this->actingAs($supervisor)->withSession([ContextoEmpresa::SESSION_KEY => $d['empresaA']->id])
        ->post('/inventario/migracion/resolver', [
            'sucursal_id' => $d['sucursalA']->id,
            'almacen_id' => $d['almacenA']->id,
        ])
        ->assertForbidden();
});
