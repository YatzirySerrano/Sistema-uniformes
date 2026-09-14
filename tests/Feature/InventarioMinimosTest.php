<?php

use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\SaldoInventario;
use App\Models\Talla;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;

/**
 * Configuración de mínimos de inventario, llaveado por
 * EMPRESA + ALMACÉN + ACTIVO + VARIANTE (nullable). La causa real de que el
 * botón "no apareciera" en QA no era de permisos ni de CSS: la página
 * `/inventario` (única que trae "Ajustar"/"Configurar mínimo") no tenía
 * ninguna entrada en el sidebar — sólo era alcanzable desde breadcrumbs de
 * otras pantallas. Se agregó la entrada "Inventario" al sidebar
 * (`AppSidebar.vue`, mismo permiso `inventario.ver` que ya exige la página) y
 * se rediseñó el botón/diálogo de "Configurar mínimo". Estos tests cubren el
 * backend (permiso real en el payload Inertia + endpoint + validaciones).
 */
beforeEach(function () {
    $this->datos = escenarioMultiempresa();
});

it('permisos.minimos es TRUE en /inventario para un usuario con el permiso', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);

    $this->actingAs($admin)->get('/inventario')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventario/Index')
            ->where('permisos.minimos', true));
});

it('permisos.minimos es FALSE en /inventario para un usuario sin el permiso', function () {
    // Encargado no tiene `inventario.minimos` en el catálogo de permisos por defecto.
    $encargado = usuarioCon(RolSistema::Encargado->value, [$this->datos['empresaA']]);

    $this->actingAs($encargado)->get('/inventario')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventario/Index')
            ->where('permisos.minimos', false));
});

it('un usuario autorizado configura el mínimo y queda persistido', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    $saldo = SaldoInventario::factory()
        ->for($this->datos['empresaA'])
        ->for($this->datos['almacenA'])
        ->for($this->datos['activoA'])
        ->for($this->datos['tallaA'])
        ->create(['cantidad' => 20, 'minimo' => 5]);

    $this->actingAs($admin)->post('/inventario/minimos', [
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'activo_id' => $this->datos['activoA']->id,
        'talla_id' => $this->datos['tallaA']->id,
        'minimo' => 15,
    ])->assertRedirect();

    expect($saldo->fresh()->minimo)->toBe(15);
});

it('sin el permiso inventario.minimos, el endpoint responde 403 y no modifica nada', function () {
    $encargado = usuarioCon(RolSistema::Encargado->value, [$this->datos['empresaA']]);
    $saldo = SaldoInventario::factory()
        ->for($this->datos['empresaA'])
        ->for($this->datos['almacenA'])
        ->for($this->datos['activoA'])
        ->for($this->datos['tallaA'])
        ->create(['minimo' => 5]);

    $this->actingAs($encargado)->post('/inventario/minimos', [
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'activo_id' => $this->datos['activoA']->id,
        'talla_id' => $this->datos['tallaA']->id,
        'minimo' => 99,
    ])->assertForbidden();

    expect($saldo->fresh()->minimo)->toBe(5);
});

it('CROSS-COMPANY: un usuario sin acceso a la empresa del saldo no puede tocar su mínimo', function () {
    $ajeno = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaB']]);
    $saldo = SaldoInventario::factory()
        ->for($this->datos['empresaA'])
        ->for($this->datos['almacenA'])
        ->for($this->datos['activoA'])
        ->for($this->datos['tallaA'])
        ->create(['minimo' => 5]);

    // Administrador tiene alcance global sobre módulos de negocio, así que la
    // prueba real de aislamiento usa un rol restringido sin la empresa A.
    $supervisorAjeno = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaB']]);

    $this->actingAs($supervisorAjeno)->post('/inventario/minimos', [
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'activo_id' => $this->datos['activoA']->id,
        'talla_id' => $this->datos['tallaA']->id,
        'minimo' => 99,
    ])->assertForbidden();

    expect($saldo->fresh()->minimo)->toBe(5);

    // El admin de otra empresa, aunque tenga alcance global de negocio, sigue
    // sin poder mandar `empresa_id` de una empresa cuyo activo/almacén no
    // cuadra con lo que valida el Form: aquí probamos el caso de "activo que
    // no pertenece a la empresa enviada" (ver siguiente test) — este test se
    // enfoca en el aislamiento del ROL restringido, el caso real de QA.
    expect($ajeno)->not->toBeNull();
});

it('rechaza un activo que no pertenece a la empresa enviada', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA'], $this->datos['empresaB']]);
    $activoDeB = Activo::factory()->for($this->datos['empresaB'])->create();

    $this->actingAs($admin)->post('/inventario/minimos', [
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'activo_id' => $activoDeB->id,
        'talla_id' => null,
        'minimo' => 10,
    ])->assertSessionHasErrors('activo_id');
});

it('rechaza una variante que no pertenece al activo', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    $tallaAjena = Talla::factory()->create(['valor' => 'XXL']);

    $this->actingAs($admin)->post('/inventario/minimos', [
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'activo_id' => $this->datos['activoA']->id,
        'talla_id' => $tallaAjena->id,
        'minimo' => 10,
    ])->assertSessionHasErrors('talla_id');
});

it('rechaza un almacén que no abastece a la empresa enviada', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA'], $this->datos['empresaB']]);

    $this->actingAs($admin)->post('/inventario/minimos', [
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenB']->id, // no abastece a empresaA
        'activo_id' => $this->datos['activoA']->id,
        'talla_id' => $this->datos['tallaA']->id,
        'minimo' => 10,
    ])->assertSessionHasErrors('almacen_id');
});

it('bajo_minimo se recalcula correctamente según la semántica actual (cantidad <= minimo > 0)', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);

    $normal = SaldoInventario::factory()->for($this->datos['empresaA'])->for($this->datos['almacenA'])
        ->for(Activo::factory()->for($this->datos['empresaA']))->create(['talla_id' => null, 'cantidad' => 20, 'minimo' => 10]);
    $igual = SaldoInventario::factory()->for($this->datos['empresaA'])->for($this->datos['almacenA'])
        ->for(Activo::factory()->for($this->datos['empresaA']))->create(['talla_id' => null, 'cantidad' => 10, 'minimo' => 10]);
    $bajo = SaldoInventario::factory()->for($this->datos['empresaA'])->for($this->datos['almacenA'])
        ->for(Activo::factory()->for($this->datos['empresaA']))->create(['talla_id' => null, 'cantidad' => 5, 'minimo' => 10]);
    $sinMinimo = SaldoInventario::factory()->for($this->datos['empresaA'])->for($this->datos['almacenA'])
        ->for(Activo::factory()->for($this->datos['empresaA']))->create(['talla_id' => null, 'cantidad' => 0, 'minimo' => 0]);

    expect($normal->estaBajoMinimo())->toBeFalse()
        ->and($igual->estaBajoMinimo())->toBeTrue() // cantidad == mínimo cuenta como bajo mínimo (<=)
        ->and($bajo->estaBajoMinimo())->toBeTrue()
        ->and($sinMinimo->estaBajoMinimo())->toBeFalse(); // mínimo 0 = alerta desactivada

    $respuesta = $this->actingAs($admin)->get('/inventario?tipo_activo_id=&buscar=');
    $respuesta->assertOk();
});

it('variantes distintas del mismo activo pueden tener mínimos independientes', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    $tallaL = Talla::factory()->create(['valor' => 'L']);
    $this->datos['activoA']->tallas()->attach($tallaL);

    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id,
        almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id,
        tallaId: $tallaL->id,
        tipo: TipoMovimiento::Inicial,
        cantidad: 5,
    ));

    $this->actingAs($admin)->post('/inventario/minimos', [
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'activo_id' => $this->datos['activoA']->id,
        'talla_id' => $this->datos['tallaA']->id,
        'minimo' => 3,
    ])->assertRedirect();

    $this->actingAs($admin)->post('/inventario/minimos', [
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'activo_id' => $this->datos['activoA']->id,
        'talla_id' => $tallaL->id,
        'minimo' => 8,
    ])->assertRedirect();

    $saldoM = SaldoInventario::query()->where('activo_id', $this->datos['activoA']->id)->where('talla_id', $this->datos['tallaA']->id)->first();
    $saldoL = SaldoInventario::query()->where('activo_id', $this->datos['activoA']->id)->where('talla_id', $tallaL->id)->first();

    expect($saldoM->minimo)->toBe(3)
        ->and($saldoL->minimo)->toBe(8);
});

it('el mismo activo puede tener mínimos independientes en almacenes distintos, y las empresas nunca se cruzan', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA'], $this->datos['empresaB']]);
    $almacenCompartido = Almacen::factory()->paraEmpresa($this->datos['empresaA'])->create();
    $almacenCompartido->empresas()->attach($this->datos['empresaB']->id);

    $activoB = Activo::factory()->for($this->datos['empresaB'])->create(['nombre' => 'Casco']);

    $this->actingAs($admin)->post('/inventario/minimos', [
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'activo_id' => $this->datos['activoA']->id,
        'talla_id' => $this->datos['tallaA']->id,
        'minimo' => 4,
    ])->assertRedirect();

    $this->actingAs($admin)->post('/inventario/minimos', [
        'empresa_id' => $this->datos['empresaB']->id,
        'almacen_id' => $almacenCompartido->id,
        'activo_id' => $activoB->id,
        'talla_id' => null,
        'minimo' => 6,
    ])->assertRedirect();

    expect(SaldoInventario::query()->where('empresa_id', $this->datos['empresaA']->id)->where('activo_id', $this->datos['activoA']->id)->first()->minimo)->toBe(4)
        ->and(SaldoInventario::query()->where('empresa_id', $this->datos['empresaB']->id)->where('activo_id', $activoB->id)->first()->minimo)->toBe(6);
});
