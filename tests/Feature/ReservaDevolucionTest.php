<?php

use App\Acciones\CrearEntregaUniforme;
use App\Acciones\ReservarCustodiaDevolucion;
use App\Acciones\ReservarInventarioEntrega;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\Activo;
use App\Models\Devolucion;
use App\Models\Reserva;
use App\Models\SaldoInventario;
use App\Models\UnidadActivo;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use App\Servicios\ServicioReservas;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Apartado temporal de CUSTODIA pendiente para Devoluciones: nunca aparta
 * stock de almacén, sólo el derecho a devolver un renglón concreto. Cubre
 * concurrencia, expiración, y que la reserva no modifica inventario.
 */
beforeEach(function () {
    Storage::fake('local');
    Mail::fake();

    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    $this->otro = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);

    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id, almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id, tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial, cantidad: 20,
    ));

    $this->entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id,
        $this->datos['almacenA']->id,
        $this->admin->id,
        now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 3]],
        [],
        [],
    );
    $this->detalle = $this->entrega->detalles->first();
});

function reservarDevolucion(array $overrides = []): array
{
    return app(ReservarCustodiaDevolucion::class)->ejecutar(
        $overrides['token'] ?? (string) Str::uuid(),
        $overrides['user_id'] ?? test()->admin->id,
        $overrides['empresa_id'] ?? test()->datos['empresaA']->id,
        $overrides['colaborador_id'] ?? test()->datos['colaboradorA']->id,
        $overrides['entrega_id'] ?? test()->entrega->id,
        $overrides['items_cantidad'] ?? [],
        $overrides['items_unidad'] ?? [],
    );
}

it('14. pendiente 3, usuario A reserva 2: usuario B sólo puede reservar 1', function () {
    $resultadoA = reservarDevolucion(['items_cantidad' => [['detalle_entrega_id' => $this->detalle->id, 'cantidad' => 2]]]);
    expect($resultadoA['ok'])->toBeTrue();

    $resultadoB = reservarDevolucion(['user_id' => $this->otro->id, 'items_cantidad' => [['detalle_entrega_id' => $this->detalle->id, 'cantidad' => 3]]]);

    expect($resultadoB['ok'])->toBeFalse()
        ->and($resultadoB['lineas_cantidad'][0]['disponible_efectivo'])->toBe(1)
        ->and($resultadoB['lineas_cantidad'][0]['pendiente_real'])->toBe(3);
});

it('15. dos usuarios intentan reservar las mismas 3: sólo uno completa', function () {
    $resultadoA = reservarDevolucion(['items_cantidad' => [['detalle_entrega_id' => $this->detalle->id, 'cantidad' => 3]]]);
    $resultadoB = reservarDevolucion(['user_id' => $this->otro->id, 'items_cantidad' => [['detalle_entrega_id' => $this->detalle->id, 'cantidad' => 3]]]);

    expect($resultadoA['ok'])->toBeTrue()
        ->and($resultadoB['ok'])->toBeFalse()
        ->and($resultadoB['lineas_cantidad'][0]['disponible_efectivo'])->toBe(0);
});

it('16. una reserva de custodia expirada no bloquea a otros', function () {
    $token = (string) Str::uuid();
    reservarDevolucion(['token' => $token, 'items_cantidad' => [['detalle_entrega_id' => $this->detalle->id, 'cantidad' => 3]]]);
    Reserva::query()->where('token', $token)->update(['expira_en' => now()->subMinute()]);

    $resultadoB = reservarDevolucion(['user_id' => $this->otro->id, 'items_cantidad' => [['detalle_entrega_id' => $this->detalle->id, 'cantidad' => 3]]]);

    expect($resultadoB['ok'])->toBeTrue();
});

it('17. cancelar libera la reserva de custodia', function () {
    $token = (string) Str::uuid();
    reservarDevolucion(['token' => $token, 'items_cantidad' => [['detalle_entrega_id' => $this->detalle->id, 'cantidad' => 3]]]);
    app(ServicioReservas::class)->liberar($token, $this->admin->id);

    $resultadoB = reservarDevolucion(['user_id' => $this->otro->id, 'items_cantidad' => [['detalle_entrega_id' => $this->detalle->id, 'cantidad' => 3]]]);

    expect($resultadoB['ok'])->toBeTrue();
});

it('18. una devolución confirmada consume la reserva de custodia', function () {
    $token = (string) Str::uuid();
    reservarDevolucion(['token' => $token, 'items_cantidad' => [['detalle_entrega_id' => $this->detalle->id, 'cantidad' => 3]]]);

    $respuesta = $this->actingAs($this->admin)->post('/devoluciones', [
        'entrega_uniforme_id' => $this->entrega->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'fecha' => now()->toDateString(),
        'firma' => firmaDemoBase64(),
        'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true,
        'reserva_token' => $token,
        'activos' => [['detalle_entrega_id' => $this->detalle->id, 'cantidad' => 3, 'condicion' => 'reutilizable']],
    ]);

    $respuesta->assertSessionHasNoErrors();
    expect(Devolucion::count())->toBe(1);

    $reserva = Reserva::query()->where('token', $token)->firstOrFail();
    expect($reserva->consumida_en)->not->toBeNull();
});

it('19. no permite reservar más que el pendiente real', function () {
    $resultado = reservarDevolucion(['items_cantidad' => [['detalle_entrega_id' => $this->detalle->id, 'cantidad' => 5]]]);

    expect($resultado['ok'])->toBeFalse()
        ->and($resultado['lineas_cantidad'][0]['pendiente_real'])->toBe(3)
        ->and($resultado['lineas_cantidad'][0]['disponible_efectivo'])->toBe(3)
        ->and($resultado['lineas_cantidad'][0]['suficiente'])->toBeFalse();
});

it('20. una unidad asignada no puede estar reservada en dos devoluciones', function () {
    $activoUnidad = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create();
    $unidad = UnidadActivo::factory()->for($this->datos['empresaA'])->for($activoUnidad, 'activo')->for($this->datos['almacenA'])->create();

    $entregaUnidad = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->admin->id, now()->toDateString(),
        [], [['unidad_activo_id' => $unidad->id]], [],
    );
    $detalleUnidad = $entregaUnidad->detalles->first();

    $resultadoA = reservarDevolucion(['entrega_id' => $entregaUnidad->id, 'items_unidad' => [['detalle_entrega_id' => $detalleUnidad->id]]]);
    $resultadoB = reservarDevolucion(['user_id' => $this->otro->id, 'entrega_id' => $entregaUnidad->id, 'items_unidad' => [['detalle_entrega_id' => $detalleUnidad->id]]]);

    expect($resultadoA['ok'])->toBeTrue()
        ->and($resultadoA['lineas_unidad'][0]['ok'])->toBeTrue()
        ->and($resultadoB['ok'])->toBeFalse()
        ->and($resultadoB['lineas_unidad'][0]['ok'])->toBeFalse();
});

it('21. una devolución parcial ya confirmada se refleja correctamente en la siguiente reserva', function () {
    // Confirma una devolución de 2 de las 3 (pendiente real pasa a 1).
    $this->actingAs($this->admin)->post('/devoluciones', [
        'entrega_uniforme_id' => $this->entrega->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'fecha' => now()->toDateString(),
        'firma' => firmaDemoBase64(),
        'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true,
        'activos' => [['detalle_entrega_id' => $this->detalle->id, 'cantidad' => 2, 'condicion' => 'reutilizable']],
    ])->assertSessionHasNoErrors();

    $resultado = reservarDevolucion(['items_cantidad' => [['detalle_entrega_id' => $this->detalle->id, 'cantidad' => 1]]]);

    expect($resultado['lineas_cantidad'][0]['pendiente_real'])->toBe(1)
        ->and($resultado['ok'])->toBeTrue();
});

it('22. reservar custodia de devolución NO modifica el inventario todavía', function () {
    $saldoAntes = (int) SaldoInventario::query()
        ->where('empresa_id', $this->datos['empresaA']->id)->where('almacen_id', $this->datos['almacenA']->id)
        ->where('activo_id', $this->datos['activoA']->id)->where('talla_id', $this->datos['tallaA']->id)
        ->value('cantidad');

    reservarDevolucion(['items_cantidad' => [['detalle_entrega_id' => $this->detalle->id, 'cantidad' => 3]]]);

    $saldoDespues = (int) SaldoInventario::query()
        ->where('empresa_id', $this->datos['empresaA']->id)->where('almacen_id', $this->datos['almacenA']->id)
        ->where('activo_id', $this->datos['activoA']->id)->where('talla_id', $this->datos['tallaA']->id)
        ->value('cantidad');

    expect($saldoDespues)->toBe($saldoAntes);
});

it('23. reservar inventario de entrega NO descuenta saldos_inventario todavía', function () {
    $saldoAntes = (int) SaldoInventario::query()
        ->where('empresa_id', $this->datos['empresaA']->id)->where('almacen_id', $this->datos['almacenA']->id)
        ->where('activo_id', $this->datos['activoA']->id)->where('talla_id', $this->datos['tallaA']->id)
        ->value('cantidad');

    app(ReservarInventarioEntrega::class)->ejecutar(
        (string) Str::uuid(), $this->admin->id, $this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['colaboradorA']->id,
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 3]], [], [],
    );

    $saldoDespues = (int) SaldoInventario::query()
        ->where('empresa_id', $this->datos['empresaA']->id)->where('almacen_id', $this->datos['almacenA']->id)
        ->where('activo_id', $this->datos['activoA']->id)->where('talla_id', $this->datos['tallaA']->id)
        ->value('cantidad');

    expect($saldoDespues)->toBe($saldoAntes);
});

it('el token de otro usuario es rechazado al reservar custodia', function () {
    $token = (string) Str::uuid();
    reservarDevolucion(['token' => $token, 'items_cantidad' => [['detalle_entrega_id' => $this->detalle->id, 'cantidad' => 1]]]);

    expect(fn () => reservarDevolucion(['token' => $token, 'user_id' => $this->otro->id, 'items_cantidad' => [['detalle_entrega_id' => $this->detalle->id, 'cantidad' => 1]]]))
        ->toThrow(ExcepcionDeNegocioSimple::class);
});
