<?php

use App\Acciones\ConfirmarAcuseDevolucion;
use App\Acciones\CrearEntregaUniforme;
use App\Acciones\RegistrarDevolucion;
use App\Enums\EstadoDevolucion;
use App\Enums\EstadoUnidadActivo;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\Activo;
use App\Models\Colaborador;
use App\Models\Devolucion;
use App\Models\MovimientoInventario;
use App\Models\SaldoInventario;
use App\Models\UnidadActivo;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);

    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id,
        almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id,
        tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial,
        cantidad: 20,
    ));

    $this->entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id,
        $this->datos['almacenA']->id,
        $this->admin->id,
        now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 10]],
        [],
        [],
    );
    $this->detalle = $this->entrega->detalles->first();
    $this->accion = app(RegistrarDevolucion::class);
});

it('registra una devolución parcial dejándola pendiente de firma, SIN modificar el inventario todavía', function () {
    $devolucion = $this->accion->ejecutar(
        $this->entrega->id,
        $this->datos['almacenA']->id,
        now()->toDateString(),
        [['detalle_entrega_id' => $this->detalle->id, 'cantidad' => 3, 'condicion' => 'reutilizable']],
        [],
        $this->admin->id,
    );

    expect($devolucion->folio)->toStartWith('DEV-')
        ->and($devolucion->estado)->toBe(EstadoDevolucion::PendienteFirma)
        ->and($devolucion->detalles)->toHaveCount(1)
        ->and($devolucion->detalles->first()->reingresa_inventario)->toBeTrue()
        ->and(SaldoInventario::first()->cantidad)->toBe(10); // 20 - 10 (entrega); aún no se confirma la devolución
});

it('acumula devoluciones parciales del mismo renglón y nunca permite exceder lo pendiente, sin tocar el saldo mientras estén pendientes', function () {
    $this->accion->ejecutar($this->entrega->id, $this->datos['almacenA']->id, now()->toDateString(), [
        ['detalle_entrega_id' => $this->detalle->id, 'cantidad' => 3, 'condicion' => 'reutilizable'],
    ], [], $this->admin->id);

    $this->accion->ejecutar($this->entrega->id, $this->datos['almacenA']->id, now()->toDateString(), [
        ['detalle_entrega_id' => $this->detalle->id, 'cantidad' => 2, 'condicion' => 'reutilizable'],
    ], [], $this->admin->id);

    // Entregados 10, devueltos (pendientes de firma) 3 + 2 = 5, pendiente = 5. Intentar devolver 6 debe rechazarse.
    expect(fn () => $this->accion->ejecutar($this->entrega->id, $this->datos['almacenA']->id, now()->toDateString(), [
        ['detalle_entrega_id' => $this->detalle->id, 'cantidad' => 6, 'condicion' => 'reutilizable'],
    ], [], $this->admin->id))->toThrow(ExcepcionDeNegocioSimple::class);

    // Devolver exactamente el pendiente (5) sí debe funcionar.
    $this->accion->ejecutar($this->entrega->id, $this->datos['almacenA']->id, now()->toDateString(), [
        ['detalle_entrega_id' => $this->detalle->id, 'cantidad' => 5, 'condicion' => 'reutilizable'],
    ], [], $this->admin->id);

    expect(Devolucion::count())->toBe(3)
        ->and(SaldoInventario::first()->cantidad)->toBe(10); // ninguna se ha confirmado: el saldo no se mueve
});

it('no reingresa al inventario un renglón devuelto en condición dañada/baja, ni antes ni después de confirmar', function () {
    $devolucion = $this->accion->ejecutar($this->entrega->id, $this->datos['almacenA']->id, now()->toDateString(), [
        ['detalle_entrega_id' => $this->detalle->id, 'cantidad' => 4, 'condicion' => 'danado'],
    ], [], $this->admin->id);

    expect(SaldoInventario::first()->cantidad)->toBe(10); // 20 - 10 entrega, nada reingresa

    Storage::fake('local');
    app(ConfirmarAcuseDevolucion::class)->ejecutar($devolucion, firmaDemoBase64(), firmaDemoBase64(), true, $this->admin->id, null, null);

    expect(SaldoInventario::first()->cantidad)->toBe(10); // dañado nunca reingresa, ni siquiera confirmada
});

it('registrar una devolución de una unidad NO la libera todavía: sigue asignada hasta que se confirme (funcionando)', function () {
    $activoIndividual = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create();
    $unidad = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($activoIndividual)->for($this->datos['almacenA'])->create();

    $entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->admin->id, now()->toDateString(),
        [], [['unidad_activo_id' => $unidad->id]], [],
    );
    $detalleUnidad = $entrega->detalles->first();

    $this->accion->ejecutar($entrega->id, $this->datos['almacenA']->id, now()->toDateString(), [], [
        ['detalle_entrega_id' => $detalleUnidad->id, 'condicion' => 'funcionando'],
    ], $this->admin->id);

    $unidad->refresh();
    expect($unidad->estado)->toBe(EstadoUnidadActivo::Asignada)
        ->and($unidad->colaborador_id)->not->toBeNull()
        ->and($unidad->esEntregable())->toBeFalse();
});

it('el formulario de creación expone el estado visible de una unidad asignada', function () {
    $activoIndividual = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create();
    $unidad = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($activoIndividual)->for($this->datos['almacenA'])->create();

    $entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->admin->id, now()->toDateString(),
        [], [['unidad_activo_id' => $unidad->id]], [],
    );

    $this->actingAs($this->admin)
        ->get("/devoluciones/crear?entrega_id={$entrega->id}")
        ->assertInertia(fn ($page) => $page
            ->component('Devoluciones/Crear')
            ->where('entrega.renglones.0.unidad_estado_visible', 'asignado')
            ->where('entrega.renglones.0.unidad_estado_visible_etiqueta', 'Asignado'),
        );
});

it('registrar una devolución de una unidad en reparación tampoco la libera todavía: sigue asignada hasta confirmar', function () {
    $activoIndividual = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create();
    $unidad = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($activoIndividual)->for($this->datos['almacenA'])->create();

    $entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->admin->id, now()->toDateString(),
        [], [['unidad_activo_id' => $unidad->id]], [],
    );
    $detalleUnidad = $entrega->detalles->first();

    $this->accion->ejecutar($entrega->id, $this->datos['almacenA']->id, now()->toDateString(), [], [
        ['detalle_entrega_id' => $detalleUnidad->id, 'condicion' => 'en_reparacion'],
    ], $this->admin->id);

    $unidad->refresh();
    expect($unidad->estado)->toBe(EstadoUnidadActivo::Asignada)
        ->and($unidad->colaborador_id)->not->toBeNull()
        ->and($unidad->esEntregable())->toBeFalse();
});

it('rechaza devolver dos veces la misma unidad mientras la primera devolución sigue pendiente de firma', function () {
    $activoIndividual = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create();
    $unidad = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($activoIndividual)->for($this->datos['almacenA'])->create();

    $entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->admin->id, now()->toDateString(),
        [], [['unidad_activo_id' => $unidad->id]], [],
    );
    $detalleUnidad = $entrega->detalles->first();

    $this->accion->ejecutar($entrega->id, $this->datos['almacenA']->id, now()->toDateString(), [], [
        ['detalle_entrega_id' => $detalleUnidad->id, 'condicion' => 'funcionando'],
    ], $this->admin->id);

    expect(fn () => $this->accion->ejecutar($entrega->id, $this->datos['almacenA']->id, now()->toDateString(), [], [
        ['detalle_entrega_id' => $detalleUnidad->id, 'condicion' => 'funcionando'],
    ], $this->admin->id))->toThrow(ExcepcionDeNegocioSimple::class);
});

it('perdido/robado no se procesa como devolución vía HTTP', function () {
    $activoIndividual = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create();
    $unidad = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($activoIndividual)->for($this->datos['almacenA'])->create();

    $entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->admin->id, now()->toDateString(),
        [], [['unidad_activo_id' => $unidad->id]], [],
    );
    $detalleUnidad = $entrega->detalles->first();

    $this->actingAs($this->admin)
        ->post('/devoluciones', [
            'entrega_uniforme_id' => $entrega->id,
            'almacen_id' => $this->datos['almacenA']->id,
            'fecha' => now()->toDateString(),
            'unidades' => [['detalle_entrega_id' => $detalleUnidad->id, 'condicion' => 'perdido']],
        ])
        ->assertSessionHasErrors('unidades.0.condicion');

    expect(Devolucion::count())->toBe(0)
        ->and($unidad->fresh()->estado)->toBe(EstadoUnidadActivo::Asignada);
});

it('rechaza registrar una devolución de un renglón que no pertenece a esa entrega', function () {
    $otraEntrega = app(CrearEntregaUniforme::class)->ejecutar(
        Colaborador::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->create()->id,
        $this->datos['almacenA']->id, $this->admin->id, now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 2]], [], [],
    );
    $detalleAjeno = $otraEntrega->detalles->first();

    expect(fn () => $this->accion->ejecutar($this->entrega->id, $this->datos['almacenA']->id, now()->toDateString(), [
        ['detalle_entrega_id' => $detalleAjeno->id, 'cantidad' => 1, 'condicion' => 'reutilizable'],
    ], [], $this->admin->id))->toThrow(ExcepcionDeNegocioSimple::class);
});

it('un rol restringido no puede registrar una devolución de una entrega fuera de su alcance (cross-company)', function () {
    $supervisorAjeno = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaB']]);
    $supervisorAjeno->givePermissionTo('devoluciones.crear');

    $this->actingAs($supervisorAjeno)
        ->post('/devoluciones', [
            'entrega_uniforme_id' => $this->entrega->id,
            'almacen_id' => $this->datos['almacenA']->id,
            'fecha' => now()->toDateString(),
            'activos' => [['detalle_entrega_id' => $this->detalle->id, 'cantidad' => 1, 'condicion' => 'reutilizable']],
        ])
        ->assertForbidden();

    expect(Devolucion::count())->toBe(0);
});

it('deja constancia en auditoría al registrar, pero el movimiento de inventario se crea hasta confirmar (CASO 1)', function () {
    $this->accion->ejecutar($this->entrega->id, $this->datos['almacenA']->id, now()->toDateString(), [
        ['detalle_entrega_id' => $this->detalle->id, 'cantidad' => 2, 'condicion' => 'reutilizable'],
    ], [], $this->admin->id);

    expect(MovimientoInventario::where('tipo', TipoMovimiento::Devolucion->value)->count())->toBe(0)
        ->and(DB::table('bitacora_auditoria')->where('accion', 'crear')->where('modulo', 'devoluciones')->exists())->toBeTrue();
});

it('el ciclo completo Almacén → colaborador A → devolución confirmada → Almacén → colaborador B funciona con el mismo activo', function () {
    Storage::fake('local');

    $devolucion = $this->accion->ejecutar($this->entrega->id, $this->datos['almacenA']->id, now()->toDateString(), [
        ['detalle_entrega_id' => $this->detalle->id, 'cantidad' => 10, 'condicion' => 'reutilizable'],
    ], [], $this->admin->id);

    // Mientras está pendiente de firma, el saldo NO refleja la devolución.
    expect(SaldoInventario::first()->cantidad)->toBe(10);

    app(ConfirmarAcuseDevolucion::class)->ejecutar($devolucion, firmaDemoBase64(), firmaDemoBase64(), true, $this->admin->id, null, null);

    // Confirmada con ambas firmas, el reingreso se aplica exactamente una vez.
    expect(SaldoInventario::first()->cantidad)->toBe(20);

    $colaboradorB = Colaborador::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->create();
    $segundaEntrega = app(CrearEntregaUniforme::class)->ejecutar(
        $colaboradorB->id, $this->datos['almacenA']->id, $this->admin->id, now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 5]], [], [],
    );

    expect($segundaEntrega->detalles->first()->cantidad)->toBe(5)
        ->and(SaldoInventario::first()->cantidad)->toBe(15); // 20 - 10 (entrega) + 10 (devolución confirmada) - 5
});
