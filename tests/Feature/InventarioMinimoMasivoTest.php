<?php

use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\SaldoInventario;
use App\Models\Talla;

/**
 * "Aplicar mínimo masivo": el mismo endpoint sirve a dos superficies —
 * `activo_id` presente = "aplicar a todas las variantes de este activo en un
 * almacén" (Activos/Detalle.vue); `activo_id` ausente = "aplicar mínimo
 * general" a toda una empresa+almacén (Inventario/Index.vue). Nunca crea
 * combinaciones nuevas, nunca sale del alcance empresa+almacén(+activo)
 * explícito, y siempre exige `inventario.minimos`.
 */
beforeEach(function () {
    $this->datos = escenarioMultiempresa();
});

it('previsualiza cuántas combinaciones de saldo existen en el alcance empresa+almacén', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    $tallaL = Talla::factory()->create(['valor' => 'L']);
    $this->datos['activoA']->tallas()->attach($tallaL);

    SaldoInventario::factory()->for($this->datos['empresaA'])->for($this->datos['almacenA'])
        ->for($this->datos['activoA'])->for($this->datos['tallaA'])->create(['minimo' => 1]);
    SaldoInventario::factory()->for($this->datos['empresaA'])->for($this->datos['almacenA'])
        ->for($this->datos['activoA'])->for($tallaL)->create(['minimo' => 2]);

    $respuesta = $this->actingAs($admin)->getJson(
        '/inventario/minimos/masivo?'.http_build_query([
            'empresa_id' => $this->datos['empresaA']->id,
            'almacen_id' => $this->datos['almacenA']->id,
        ]),
    )->assertOk();

    expect($respuesta->json('combinaciones'))->toBe(2);
});

it('previsualiza y aplica el mínimo acotado a UN SOLO activo cuando se envía activo_id', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    $otroActivo = Activo::factory()->for($this->datos['empresaA'])->create(['nombre' => 'Pantalón']);

    $saldoActivoA = SaldoInventario::factory()->for($this->datos['empresaA'])->for($this->datos['almacenA'])
        ->for($this->datos['activoA'])->for($this->datos['tallaA'])->create(['minimo' => 1]);
    $saldoOtroActivo = SaldoInventario::factory()->for($this->datos['empresaA'])->for($this->datos['almacenA'])
        ->for($otroActivo)->create(['talla_id' => null, 'minimo' => 1]);

    $preview = $this->actingAs($admin)->getJson(
        '/inventario/minimos/masivo?'.http_build_query([
            'empresa_id' => $this->datos['empresaA']->id,
            'almacen_id' => $this->datos['almacenA']->id,
            'activo_id' => $this->datos['activoA']->id,
        ]),
    )->assertOk();
    expect($preview->json('combinaciones'))->toBe(1);

    $this->actingAs($admin)->post('/inventario/minimos/masivo', [
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'activo_id' => $this->datos['activoA']->id,
        'minimo' => 9,
    ])->assertRedirect();

    expect($saldoActivoA->fresh()->minimo)->toBe(9)
        ->and($saldoOtroActivo->fresh()->minimo)->toBe(1); // otro activo del mismo almacén: intacto
});

it('aplica el mínimo general a TODA la empresa+almacén cuando NO se envía activo_id', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    $otroActivo = Activo::factory()->for($this->datos['empresaA'])->create(['nombre' => 'Pantalón']);

    $saldoActivoA = SaldoInventario::factory()->for($this->datos['empresaA'])->for($this->datos['almacenA'])
        ->for($this->datos['activoA'])->for($this->datos['tallaA'])->create(['minimo' => 1]);
    $saldoOtroActivo = SaldoInventario::factory()->for($this->datos['empresaA'])->for($this->datos['almacenA'])
        ->for($otroActivo)->create(['talla_id' => null, 'minimo' => 1]);

    $this->actingAs($admin)->post('/inventario/minimos/masivo', [
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'minimo' => 7,
    ])->assertRedirect();

    expect($saldoActivoA->fresh()->minimo)->toBe(7)
        ->and($saldoOtroActivo->fresh()->minimo)->toBe(7);
});

it('NUNCA cruza a otro almacén ni a otra empresa: sólo se tocan filas del alcance exacto', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA'], $this->datos['empresaB']]);
    $almacenA2 = Almacen::factory()->paraEmpresa($this->datos['empresaA'])->create(['nombre' => 'Almacén A-2']);
    $activoB = Activo::factory()->for($this->datos['empresaB'])->create(['nombre' => 'Casco']);

    $saldoAlmacenA = SaldoInventario::factory()->for($this->datos['empresaA'])->for($this->datos['almacenA'])
        ->for($this->datos['activoA'])->for($this->datos['tallaA'])->create(['minimo' => 1]);
    $saldoAlmacenA2 = SaldoInventario::factory()->for($this->datos['empresaA'])->for($almacenA2)
        ->for($this->datos['activoA'])->for($this->datos['tallaA'])->create(['minimo' => 1]);
    $saldoEmpresaB = SaldoInventario::factory()->for($this->datos['empresaB'])->for($this->datos['almacenB'])
        ->for($activoB)->create(['talla_id' => null, 'minimo' => 1]);

    $this->actingAs($admin)->post('/inventario/minimos/masivo', [
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'minimo' => 50,
    ])->assertRedirect();

    expect($saldoAlmacenA->fresh()->minimo)->toBe(50)
        ->and($saldoAlmacenA2->fresh()->minimo)->toBe(1)
        ->and($saldoEmpresaB->fresh()->minimo)->toBe(1);
});

it('sin el permiso inventario.minimos, previsualizar y aplicar responden 403 y no modifican nada', function () {
    $encargado = usuarioCon(RolSistema::Encargado->value, [$this->datos['empresaA']]);
    $saldo = SaldoInventario::factory()->for($this->datos['empresaA'])->for($this->datos['almacenA'])
        ->for($this->datos['activoA'])->for($this->datos['tallaA'])->create(['minimo' => 1]);

    $this->actingAs($encargado)->getJson(
        '/inventario/minimos/masivo?'.http_build_query([
            'empresa_id' => $this->datos['empresaA']->id,
            'almacen_id' => $this->datos['almacenA']->id,
        ]),
    )->assertForbidden();

    $this->actingAs($encargado)->post('/inventario/minimos/masivo', [
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'minimo' => 99,
    ])->assertForbidden();

    expect($saldo->fresh()->minimo)->toBe(1);
});

it('CROSS-COMPANY: un usuario sin acceso a la empresa no puede aplicar el mínimo masivo', function () {
    $ajeno = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaB']]);
    $saldo = SaldoInventario::factory()->for($this->datos['empresaA'])->for($this->datos['almacenA'])
        ->for($this->datos['activoA'])->for($this->datos['tallaA'])->create(['minimo' => 1]);

    $this->actingAs($ajeno)->post('/inventario/minimos/masivo', [
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'minimo' => 99,
    ])->assertForbidden();

    expect($saldo->fresh()->minimo)->toBe(1);
});

it('rechaza un almacén que no abastece a la empresa enviada', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA'], $this->datos['empresaB']]);

    $this->actingAs($admin)->post('/inventario/minimos/masivo', [
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenB']->id, // no abastece a empresaA
        'minimo' => 10,
    ])->assertSessionHasErrors('almacen_id');
});

it('rechaza un activo que no pertenece a la empresa enviada', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA'], $this->datos['empresaB']]);
    $activoDeB = Activo::factory()->for($this->datos['empresaB'])->create();

    $this->actingAs($admin)->post('/inventario/minimos/masivo', [
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'activo_id' => $activoDeB->id,
        'minimo' => 10,
    ])->assertSessionHasErrors('activo_id');
});

it('un alcance sin ninguna combinación existente aplica cero filas sin error', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);

    $respuesta = $this->actingAs($admin)->post('/inventario/minimos/masivo', [
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'minimo' => 10,
    ])->assertRedirect();

    $respuesta->assertSessionHas('toast.message', 'Mínimo aplicado a 0 combinación(es).');
});

it('una fila con cantidad 0 y mínimo 0 (sin alerta) queda BAJO MÍNIMO tras aplicar un mínimo general > 0', function () {
    // Semántica existente, nunca reinventada: estaBajoMinimo() = minimo > 0
    // && cantidad <= minimo. Antes de la operación masiva, minimo=0 desactiva
    // la alerta aunque cantidad sea 0; después, al fijar minimo=10 sin tocar
    // la existencia, la fila pasa a bajo mínimo — es el comportamiento
    // correcto (0 unidades es, literalmente, estar por debajo de 10), no un
    // efecto colateral a corregir.
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    $saldo = SaldoInventario::factory()->for($this->datos['empresaA'])->for($this->datos['almacenA'])
        ->for($this->datos['activoA'])->for($this->datos['tallaA'])->create(['cantidad' => 0, 'minimo' => 0]);

    expect($saldo->estaBajoMinimo())->toBeFalse();

    $this->actingAs($admin)->post('/inventario/minimos/masivo', [
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'minimo' => 10,
    ])->assertRedirect();

    $saldo->refresh();
    expect($saldo->cantidad)->toBe(0)
        ->and($saldo->minimo)->toBe(10)
        ->and($saldo->estaBajoMinimo())->toBeTrue();
});

it('Activos/Detalle expone empresa_id/almacen_id/activo_id/talla_id por fila y el permiso minimos', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    SaldoInventario::factory()->for($this->datos['empresaA'])->for($this->datos['almacenA'])
        ->for($this->datos['activoA'])->for($this->datos['tallaA'])->create(['cantidad' => 3, 'minimo' => 1]);

    $this->actingAs($admin)->get("/activos/{$this->datos['activoA']->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Activos/Detalle')
            ->where('permisos.minimos', true)
            ->where('saldos.0.empresa_id', $this->datos['empresaA']->id)
            ->where('saldos.0.almacen_id', $this->datos['almacenA']->id)
            ->where('saldos.0.activo_id', $this->datos['activoA']->id)
            ->where('saldos.0.talla_id', $this->datos['tallaA']->id));
});
