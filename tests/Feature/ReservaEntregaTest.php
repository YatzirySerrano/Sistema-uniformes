<?php

use App\Acciones\ReservarInventarioEntrega;
use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoUnidadActivo;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\Activo;
use App\Models\Conjunto;
use App\Models\ConjuntoComponente;
use App\Models\EntregaUniforme;
use App\Models\Reserva;
use App\Models\SaldoInventario;
use App\Models\UnidadActivo;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioAcusePdf;
use App\Servicios\ServicioInventario;
use App\Servicios\ServicioReservas;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Apartado temporal de inventario para Entregas: concurrencia, expiración,
 * agregación de demanda combinada (artículo suelto + conjuntos) y que la
 * reserva NUNCA reemplaza la validación autoritativa final.
 */
beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
    $this->mock(ServicioAcusePdf::class, function ($mock): void {
        $mock->shouldReceive('generar')->andReturn('acuses/fake.pdf');
        $mock->shouldReceive('contenido')->andReturn(null);
    });

    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA'], $this->datos['empresaB']]);
    $this->otro = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);

    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id, almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id, tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial, cantidad: 6,
    ));
});

function reservarEntrega(array $overrides = []): array
{
    return app(ReservarInventarioEntrega::class)->ejecutar(
        $overrides['token'] ?? (string) Str::uuid(),
        $overrides['user_id'] ?? test()->admin->id,
        $overrides['empresa_id'] ?? test()->datos['empresaA']->id,
        $overrides['almacen_id'] ?? test()->datos['almacenA']->id,
        $overrides['colaborador_id'] ?? test()->datos['colaboradorA']->id,
        $overrides['activos'] ?? [],
        $overrides['unidades'] ?? [],
        $overrides['conjuntos'] ?? [],
    );
}

function filaActivoA(int $cantidad): array
{
    return ['activo_id' => test()->datos['activoA']->id, 'talla_id' => test()->datos['tallaA']->id, 'cantidad' => $cantidad];
}

it('1. stock 6, usuario A reserva 6: usuario B no puede reservar 1', function () {
    $tokenA = (string) Str::uuid();
    $resultadoA = reservarEntrega(['token' => $tokenA, 'activos' => [filaActivoA(6)]]);
    expect($resultadoA['ok'])->toBeTrue()
        ->and($resultadoA['lineas_cantidad'][0]['disponible_efectivo'])->toBe(6);

    $tokenB = (string) Str::uuid();
    $resultadoB = reservarEntrega(['token' => $tokenB, 'user_id' => $this->otro->id, 'activos' => [filaActivoA(1)]]);

    expect($resultadoB['ok'])->toBeFalse()
        ->and($resultadoB['lineas_cantidad'][0]['disponible_efectivo'])->toBe(0)
        ->and($resultadoB['lineas_cantidad'][0]['suficiente'])->toBeFalse();
});

it('2. dos intentos concurrentes por el total del stock: sólo uno obtiene la reserva completa', function () {
    $resultadoA = reservarEntrega(['activos' => [filaActivoA(6)]]);
    $resultadoB = reservarEntrega(['user_id' => $this->otro->id, 'activos' => [filaActivoA(6)]]);

    expect($resultadoA['ok'])->toBeTrue()
        ->and($resultadoB['ok'])->toBeFalse()
        ->and($resultadoB['lineas_cantidad'][0]['disponible_efectivo'])->toBe(0);
});

it('3. una reserva expirada no bloquea a otros', function () {
    $tokenA = (string) Str::uuid();
    reservarEntrega(['token' => $tokenA, 'activos' => [filaActivoA(6)]]);
    Reserva::query()->where('token', $tokenA)->update(['expira_en' => now()->subMinute()]);

    $resultadoB = reservarEntrega(['user_id' => $this->otro->id, 'activos' => [filaActivoA(6)]]);

    expect($resultadoB['ok'])->toBeTrue();
});

it('4. cancelar libera la reserva de inmediato', function () {
    $tokenA = (string) Str::uuid();
    reservarEntrega(['token' => $tokenA, 'activos' => [filaActivoA(6)]]);
    app(ServicioReservas::class)->liberar($tokenA, $this->admin->id);

    $resultadoB = reservarEntrega(['user_id' => $this->otro->id, 'activos' => [filaActivoA(6)]]);

    expect($resultadoB['ok'])->toBeTrue();
});

it('5. una entrega registrada exitosamente consume la reserva', function () {
    $token = (string) Str::uuid();
    reservarEntrega(['token' => $token, 'activos' => [filaActivoA(6)]]);

    $respuesta = $this->actingAs($this->admin)->post('/entregas', [
        'colaborador_id' => $this->datos['colaboradorA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'fecha_entrega' => now()->toDateString(),
        'firma' => firmaDemoBase64(),
        'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true,
        'reserva_token' => $token,
        'activos' => [filaActivoA(6)],
    ]);

    $respuesta->assertSessionHasNoErrors();
    expect(EntregaUniforme::count())->toBe(1);

    $reserva = Reserva::query()->where('token', $token)->firstOrFail();
    expect($reserva->consumida_en)->not->toBeNull();
});

it('6. el token de otro usuario es rechazado', function () {
    $token = (string) Str::uuid();
    reservarEntrega(['token' => $token, 'activos' => [filaActivoA(6)]]);

    expect(fn () => reservarEntrega(['token' => $token, 'user_id' => $this->otro->id, 'activos' => [filaActivoA(1)]]))
        ->toThrow(ExcepcionDeNegocioSimple::class);
});

it('7. artículo suelto 6 más un conjunto que consume otras 6 de la misma variante: demanda total 12 detectada y rechazada', function () {
    $activoB = Activo::factory()->for($this->datos['empresaA'])->create(['nombre' => 'Pantalón']);
    $conjunto = Conjunto::factory()->for($this->datos['empresaA'])->create();
    ConjuntoComponente::factory()->for($conjunto)->create([
        'activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad_requerida' => 1,
    ]);
    ConjuntoComponente::factory()->for($conjunto)->create([
        'activo_id' => $activoB->id, 'talla_id' => null, 'cantidad_requerida' => 1,
    ]);
    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id, almacenId: $this->datos['almacenA']->id,
        activoId: $activoB->id, tallaId: null, tipo: TipoMovimiento::Inicial, cantidad: 6,
    ));

    $resultado = reservarEntrega([
        'activos' => [filaActivoA(6)],
        'conjuntos' => [['conjunto_id' => $conjunto->id, 'cantidad' => 6, 'variantes' => []]],
    ]);

    expect($resultado['ok'])->toBeFalse();
    $linea = collect($resultado['lineas_cantidad'])->firstWhere('activo_id', $this->datos['activoA']->id);
    expect($linea['solicitado_combinado'])->toBe(12)
        ->and($linea['disponible_efectivo'])->toBe(6)
        ->and($linea['suficiente'])->toBeFalse();
    $conjuntoReporte = collect($resultado['conjuntos'])->first();
    expect($conjuntoReporte['suficiente'])->toBeFalse();
});

it('8. dos conjuntos que consumen la misma variante agregan su demanda', function () {
    $conjunto = Conjunto::factory()->for($this->datos['empresaA'])->create();
    ConjuntoComponente::factory()->for($conjunto)->create([
        'activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad_requerida' => 3,
    ]);

    $resultado = reservarEntrega([
        'conjuntos' => [
            ['conjunto_id' => $conjunto->id, 'cantidad' => 2, 'variantes' => []],
            ['conjunto_id' => $conjunto->id, 'cantidad' => 1, 'variantes' => []],
        ],
    ]);

    // 2*3 + 1*3 = 9 > 6 disponibles: agregado detectado, ambos renglones insuficientes.
    expect($resultado['ok'])->toBeFalse();
    $linea = collect($resultado['lineas_cantidad'])->first();
    expect($linea['solicitado_combinado'])->toBe(9);
});

it('9. dos filas sueltas iguales se agregan', function () {
    $resultado = reservarEntrega(['activos' => [filaActivoA(4), filaActivoA(4)]]);

    expect($resultado['ok'])->toBeFalse();
    $linea = collect($resultado['lineas_cantidad'])->first();
    expect($linea['solicitado_combinado'])->toBe(8)
        ->and($linea['disponible_efectivo'])->toBe(6);
});

it('10. una unidad individual no puede reservarse por dos borradores', function () {
    $activoUnidad = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create();
    $unidad = UnidadActivo::factory()->for($this->datos['empresaA'])->for($activoUnidad, 'activo')->for($this->datos['almacenA'])->create([
        'estado' => EstadoUnidadActivo::EnAlmacen, 'condicion' => CondicionUnidadActivo::Funcionando,
    ]);

    $resultadoA = reservarEntrega(['unidades' => [['unidad_activo_id' => $unidad->id]]]);
    $resultadoB = reservarEntrega(['user_id' => $this->otro->id, 'unidades' => [['unidad_activo_id' => $unidad->id]]]);

    expect($resultadoA['ok'])->toBeTrue()
        ->and($resultadoA['lineas_unidad'][0]['ok'])->toBeTrue()
        ->and($resultadoB['ok'])->toBeFalse()
        ->and($resultadoB['lineas_unidad'][0]['ok'])->toBeFalse();
});

it('11. reserva vigente pero el stock cambió por una operación legítima: la confirmación final vuelve a validar y rechaza', function () {
    $token = (string) Str::uuid();
    reservarEntrega(['token' => $token, 'activos' => [filaActivoA(6)]]);

    // Una salida directa (ajuste) reduce el stock real DESPUÉS de reservar,
    // saltándose la reserva (simula otra operación legítima del sistema).
    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id, almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id, tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::AjusteSalida, cantidad: 6, motivo: 'merma',
    ));

    $respuesta = $this->actingAs($this->admin)->post('/entregas', [
        'colaborador_id' => $this->datos['colaboradorA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'fecha_entrega' => now()->toDateString(),
        'firma' => firmaDemoBase64(),
        'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true,
        'reserva_token' => $token,
        'activos' => [filaActivoA(6)],
    ]);

    // Puede rechazarse en la validación previa (saldo ya en 0) o en el
    // registro autoritativo — cualquiera de las dos capas es válida aquí;
    // lo que importa es que NO se registre nada.
    $respuesta->assertSessionHasErrors();
    expect(EntregaUniforme::count())->toBe(0);
});

it('12. una reserva vencida no permite confirmar la entrega', function () {
    $token = (string) Str::uuid();
    reservarEntrega(['token' => $token, 'activos' => [filaActivoA(6)]]);
    Reserva::query()->where('token', $token)->update(['expira_en' => now()->subMinute()]);

    $respuesta = $this->actingAs($this->admin)->post('/entregas', [
        'colaborador_id' => $this->datos['colaboradorA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'fecha_entrega' => now()->toDateString(),
        'firma' => firmaDemoBase64(),
        'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true,
        'reserva_token' => $token,
        'activos' => [filaActivoA(6)],
    ]);

    $respuesta->assertSessionHasErrors('negocio');
    expect(EntregaUniforme::count())->toBe(0)
        ->and((int) SaldoInventario::first()->cantidad)->toBe(6);
});

it('13. la idempotencia por idempotency_key sigue funcionando junto con el token de reserva', function () {
    // Sólo se pide 3 de las 6 disponibles: al reintentar deja stock de sobra
    // (3), así el segundo rechazo es INEQUÍVOCAMENTE por la idempotencia y
    // no porque ya no alcance el saldo.
    $token = (string) Str::uuid();
    reservarEntrega(['token' => $token, 'activos' => [filaActivoA(3)]]);
    $clave = (string) Str::uuid();

    $payload = [
        'colaborador_id' => $this->datos['colaboradorA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'fecha_entrega' => now()->toDateString(),
        'firma' => firmaDemoBase64(),
        'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true,
        'reserva_token' => $token,
        'idempotency_key' => $clave,
        'activos' => [filaActivoA(3)],
    ];

    $this->actingAs($this->admin)->post('/entregas', $payload)->assertSessionHasNoErrors();
    // Reintento con la MISMA clave: rechazado sin registrar una segunda entrega.
    $this->actingAs($this->admin)->post('/entregas', $payload)->assertSessionHasErrors('negocio');

    expect(EntregaUniforme::count())->toBe(1);
});
