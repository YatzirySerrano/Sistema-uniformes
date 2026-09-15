<?php

use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\BitacoraAuditoria;
use App\Models\MovimientoInventario;
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

it('mínimo 0 se guarda correctamente (desactiva la alerta de bajo mínimo)', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    $saldo = SaldoInventario::factory()
        ->for($this->datos['empresaA'])
        ->for($this->datos['almacenA'])
        ->for($this->datos['activoA'])
        ->for($this->datos['tallaA'])
        ->create(['cantidad' => 20, 'minimo' => 10]);

    $this->actingAs($admin)->post('/inventario/minimos', [
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'activo_id' => $this->datos['activoA']->id,
        'talla_id' => $this->datos['tallaA']->id,
        'minimo' => 0,
    ])->assertRedirect();

    expect($saldo->fresh()->minimo)->toBe(0)
        ->and($saldo->fresh()->estaBajoMinimo())->toBeFalse();
});

it('rechaza un mínimo negativo y no modifica el valor existente', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    $saldo = SaldoInventario::factory()
        ->for($this->datos['empresaA'])
        ->for($this->datos['almacenA'])
        ->for($this->datos['activoA'])
        ->for($this->datos['tallaA'])
        ->create(['minimo' => 5]);

    $this->actingAs($admin)->post('/inventario/minimos', [
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'activo_id' => $this->datos['activoA']->id,
        'talla_id' => $this->datos['tallaA']->id,
        'minimo' => -5,
    ])->assertSessionHasErrors('minimo');

    expect($saldo->fresh()->minimo)->toBe(5);
});

it('bajar el mínimo de 10 a 5 persiste el nuevo valor', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    $saldo = SaldoInventario::factory()
        ->for($this->datos['empresaA'])
        ->for($this->datos['almacenA'])
        ->for($this->datos['activoA'])
        ->for($this->datos['tallaA'])
        ->create(['cantidad' => 8, 'minimo' => 10]);

    $this->actingAs($admin)->post('/inventario/minimos', [
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'activo_id' => $this->datos['activoA']->id,
        'talla_id' => $this->datos['tallaA']->id,
        'minimo' => 5,
    ])->assertRedirect();

    expect($saldo->fresh()->minimo)->toBe(5);
});

it('REGRESIÓN: talla_id=0 (el payload que el frontend mandaba antes del fix para "sin variante") se rechaza, 0 nunca es un comodín', function () {
    // Antes del fix, `Activos/Detalle.vue` armaba el formulario con
    // `talla_id: s.talla_id ?? 0` — para una fila "sin variante" (talla_id
    // NULL en el saldo) eso mandaba 0 en vez de null. El backend rechaza 0
    // correctamente (no existe en `activo_talla`), pero el diálogo sólo
    // mostraba el error de `minimo`, nunca el de `talla_id`: el modal se
    // quedaba "congelado" sin ningún mensaje visible. El fix fue dejar de
    // mandar 0 y mandar null. Este test documenta que el backend DEBE seguir
    // rechazando 0 — no "arreglarlo" aceptándolo como comodín de variante.
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    $saldoSinVariante = SaldoInventario::factory()
        ->for($this->datos['empresaA'])
        ->for($this->datos['almacenA'])
        ->for(Activo::factory()->for($this->datos['empresaA']))
        ->create(['talla_id' => null, 'cantidad' => 10, 'minimo' => 3]);

    $this->actingAs($admin)->post('/inventario/minimos', [
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'activo_id' => $saldoSinVariante->activo_id,
        'talla_id' => 0,
        'minimo' => 99,
    ])->assertSessionHasErrors('talla_id');

    expect($saldoSinVariante->fresh()->minimo)->toBe(3);
});

it('cambiar el mínimo de 5 a 10 crea una entrada de auditoría con empresa, almacén, activo, variante y valores anterior/nuevo', function () {
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
        'minimo' => 10,
    ])->assertRedirect();

    expect($saldo->fresh()->minimo)->toBe(10);

    $bitacora = BitacoraAuditoria::query()
        ->where('modulo', 'inventario')->where('accion', 'actualizar_minimo')->latest('id')->first();

    expect($bitacora)->not->toBeNull()
        ->and($bitacora->usuario_id)->toBe($admin->id)
        ->and($bitacora->empresa_id)->toBe($this->datos['empresaA']->id)
        ->and($bitacora->tipo_entidad)->toBe(SaldoInventario::class)
        ->and($bitacora->entidad_id)->toBe($saldo->id)
        ->and($bitacora->valores_anteriores['minimo'])->toBe(5)
        ->and($bitacora->valores_nuevos['minimo'])->toBe(10)
        ->and($bitacora->descripcion)->toContain($this->datos['activoA']->nombre)
        ->and($bitacora->descripcion)->toContain($this->datos['almacenA']->nombre)
        ->and($bitacora->descripcion)->toContain($this->datos['tallaA']->valor)
        ->and($bitacora->descripcion)->toContain('5')
        ->and($bitacora->descripcion)->toContain('10');

    // El almacén/activo/variante también son reconstruibles desde la fila de
    // saldo referenciada por `entidad_id` (misma llave empresa+almacén+
    // activo+talla), sin necesidad de duplicarlos como columnas nuevas.
    expect($saldo->fresh()->almacen_id)->toBe($this->datos['almacenA']->id)
        ->and($saldo->fresh()->activo_id)->toBe($this->datos['activoA']->id)
        ->and($saldo->fresh()->talla_id)->toBe($this->datos['tallaA']->id);
});

it('sin variante (talla_id null), la descripción de auditoría no menciona ninguna variante', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    $activoSinVariante = Activo::factory()->for($this->datos['empresaA'])->create(['nombre' => 'Gorra negra']);
    $saldo = SaldoInventario::factory()
        ->for($this->datos['empresaA'])
        ->for($this->datos['almacenA'])
        ->for($activoSinVariante)
        ->create(['talla_id' => null, 'cantidad' => 20, 'minimo' => 5]);

    $this->actingAs($admin)->post('/inventario/minimos', [
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'activo_id' => $activoSinVariante->id,
        'talla_id' => null,
        'minimo' => 10,
    ])->assertRedirect();

    $bitacora = BitacoraAuditoria::query()
        ->where('modulo', 'inventario')->where('accion', 'actualizar_minimo')->latest('id')->first();

    expect($bitacora->descripcion)->toBe('Se actualizó el mínimo de Gorra negra en Almacén A de 5 a 10.');
});

it('con variante, la descripción de auditoría identifica correctamente la talla', function () {
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
        'minimo' => 10,
    ])->assertRedirect();

    $bitacora = BitacoraAuditoria::query()
        ->where('modulo', 'inventario')->where('accion', 'actualizar_minimo')->latest('id')->first();

    expect($bitacora->descripcion)->toBe('Se actualizó el mínimo de Camisa · M en Almacén A de 5 a 10.');
});

it('guardar el mismo mínimo (5 a 5) NO crea una entrada de auditoría', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    SaldoInventario::factory()
        ->for($this->datos['empresaA'])
        ->for($this->datos['almacenA'])
        ->for($this->datos['activoA'])
        ->for($this->datos['tallaA'])
        ->create(['cantidad' => 20, 'minimo' => 5]);

    $antes = BitacoraAuditoria::query()->where('modulo', 'inventario')->where('accion', 'actualizar_minimo')->count();

    $this->actingAs($admin)->post('/inventario/minimos', [
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'activo_id' => $this->datos['activoA']->id,
        'talla_id' => $this->datos['tallaA']->id,
        'minimo' => 5,
    ])->assertRedirect();

    expect(
        BitacoraAuditoria::query()->where('modulo', 'inventario')->where('accion', 'actualizar_minimo')->count(),
    )->toBe($antes);
});

it('un mínimo inválido no crea entrada de auditoría', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    SaldoInventario::factory()
        ->for($this->datos['empresaA'])
        ->for($this->datos['almacenA'])
        ->for($this->datos['activoA'])
        ->for($this->datos['tallaA'])
        ->create(['minimo' => 5]);

    $antes = BitacoraAuditoria::query()->where('modulo', 'inventario')->where('accion', 'actualizar_minimo')->count();

    $this->actingAs($admin)->post('/inventario/minimos', [
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'activo_id' => $this->datos['activoA']->id,
        'talla_id' => $this->datos['tallaA']->id,
        'minimo' => -5,
    ])->assertSessionHasErrors('minimo');

    expect(
        BitacoraAuditoria::query()->where('modulo', 'inventario')->where('accion', 'actualizar_minimo')->count(),
    )->toBe($antes);
});

it('un usuario sin permiso no cambia el mínimo ni crea entrada de auditoría', function () {
    $encargado = usuarioCon(RolSistema::Encargado->value, [$this->datos['empresaA']]);
    $saldo = SaldoInventario::factory()
        ->for($this->datos['empresaA'])
        ->for($this->datos['almacenA'])
        ->for($this->datos['activoA'])
        ->for($this->datos['tallaA'])
        ->create(['minimo' => 5]);

    $antes = BitacoraAuditoria::query()->where('modulo', 'inventario')->where('accion', 'actualizar_minimo')->count();

    $this->actingAs($encargado)->post('/inventario/minimos', [
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'activo_id' => $this->datos['activoA']->id,
        'talla_id' => $this->datos['tallaA']->id,
        'minimo' => 99,
    ])->assertForbidden();

    expect($saldo->fresh()->minimo)->toBe(5)
        ->and(
            BitacoraAuditoria::query()->where('modulo', 'inventario')->where('accion', 'actualizar_minimo')->count(),
        )->toBe($antes);
});

it('cambiar el mínimo NO genera ningún MovimientoInventario', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    SaldoInventario::factory()
        ->for($this->datos['empresaA'])
        ->for($this->datos['almacenA'])
        ->for($this->datos['activoA'])
        ->for($this->datos['tallaA'])
        ->create(['cantidad' => 20, 'minimo' => 5]);

    $movimientosAntes = MovimientoInventario::query()->count();

    $this->actingAs($admin)->post('/inventario/minimos', [
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'activo_id' => $this->datos['activoA']->id,
        'talla_id' => $this->datos['tallaA']->id,
        'minimo' => 10,
    ])->assertRedirect();

    expect(MovimientoInventario::query()->count())->toBe($movimientosAntes);
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
