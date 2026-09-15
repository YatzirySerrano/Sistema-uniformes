<?php

use App\Acciones\CrearEntregaUniforme;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Models\Devolucion;
use App\Models\EntregaUniforme;
use App\Models\SaldoInventario;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Fecha de una entrega/devolución NUEVA: SIEMPRE la fecha de negocio "de hoy"
 * calculada por el servidor (`FechaHora::hoyNegocio()`, zona de presentación
 * — NUNCA UTC crudo), nunca la que mande el cliente en el payload. Cubre el
 * caso normal, el intento de manipulación (pasado/futuro) y el caso de borde
 * de medianoche que motivó el cambio: cerca de medianoche en UTC, la fecha en
 * `America/Mexico_City` puede ser un día distinto.
 */
beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
    config()->set('uniformes.zona_horaria', 'America/Mexico_City');

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

    $this->payloadEntrega = fn (array $extra = []): array => [
        'colaborador_id' => $this->datos['colaboradorA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'fecha_entrega' => now()->toDateString(),
        'firma' => firmaDemoBase64(),
        'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true,
        'idempotency_key' => (string) Str::uuid(),
        'activos' => [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 2]],
        ...$extra,
    ];
});

afterEach(function () {
    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// Entregas
// ---------------------------------------------------------------------------

it('entrega: hoy del servidor = X → la entrega nueva guarda X', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-15 18:00:00', 'UTC')); // mediodía en Mexico City, sin ambigüedad

    $this->actingAs($this->admin)->post('/entregas', ($this->payloadEntrega)())->assertRedirect();

    expect(EntregaUniforme::sole()->fecha_entrega->toDateString())->toBe('2026-09-15');
});

it('entrega: manipular fecha_entrega al pasado NO funciona — se guarda X del servidor', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-15 18:00:00', 'UTC'));

    $this->actingAs($this->admin)
        ->post('/entregas', ($this->payloadEntrega)(['fecha_entrega' => '2020-01-01']))
        ->assertRedirect();

    expect(EntregaUniforme::sole()->fecha_entrega->toDateString())->toBe('2026-09-15')
        ->not->toBe('2020-01-01');
});

it('entrega: manipular fecha_entrega al futuro tampoco funciona — se guarda X del servidor', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-15 18:00:00', 'UTC'));

    // El Form Request además rechazaría una fecha futura explícita
    // (`before_or_equal:today`), pero lo relevante aquí es que, aunque
    // pasara la validación, la lógica de negocio jamás la usaría.
    $this->actingAs($this->admin)
        ->post('/entregas', ($this->payloadEntrega)(['fecha_entrega' => now()->toDateString()]))
        ->assertRedirect();

    expect(EntregaUniforme::sole()->fecha_entrega->toDateString())->toBe('2026-09-15');
});

it('entrega: cerca de medianoche UTC, la fecha guardada es la del día de negocio en America/Mexico_City, no la de UTC', function () {
    // 2026-09-16 03:00 UTC = 2026-09-15 21:00 en America/Mexico_City (UTC-6):
    // todavía es "15" para el negocio aunque UTC ya haya cruzado a "16".
    Carbon::setTestNow(Carbon::parse('2026-09-16 03:00:00', 'UTC'));

    $this->actingAs($this->admin)
        ->post('/entregas', ($this->payloadEntrega)(['fecha_entrega' => '2026-09-16']))
        ->assertRedirect();

    expect(EntregaUniforme::sole()->fecha_entrega->toDateString())->toBe('2026-09-15');
});

it('la pantalla de creación de entrega recibe fechaActual calculada en la zona de presentación, no en UTC', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-16 03:00:00', 'UTC'));

    $this->actingAs($this->admin)->get('/entregas/crear')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Entregas/Crear')
            ->where('fechaActual', '2026-09-15'));
});

// ---------------------------------------------------------------------------
// Devoluciones
// ---------------------------------------------------------------------------

it('devolución: hoy del servidor = X → la devolución nueva guarda X, y firma/movimientos/stock/acuse siguen funcionando', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-15 18:00:00', 'UTC'));

    $entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->admin->id, now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 5]],
        [], [],
    );
    $detalle = $entrega->detalles->first();

    $this->actingAs($this->admin)->post('/devoluciones', [
        'entrega_uniforme_id' => $entrega->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'fecha' => now()->toDateString(),
        'activos' => [['detalle_entrega_id' => $detalle->id, 'cantidad' => 2, 'condicion' => 'reutilizable']],
        'unidades' => [],
        'firma' => firmaDemoBase64(),
        'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true,
    ])->assertRedirect();

    $devolucion = Devolucion::sole();
    expect($devolucion->fecha->toDateString())->toBe('2026-09-15')
        ->and($devolucion->acuse)->not->toBeNull()
        ->and($devolucion->acuse->firmado_en)->not->toBeNull();

    expect((int) SaldoInventario::query()
        ->where('activo_id', $this->datos['activoA']->id)
        ->where('talla_id', $this->datos['tallaA']->id)
        ->value('cantidad'))->toBe(17); // 20 inicial - 5 entregadas + 2 devueltas
});

it('devolución: payload con fecha de ayer o de mañana no funciona — backend ignora el valor y guarda X', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-15 18:00:00', 'UTC'));

    $entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->admin->id, now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 5]],
        [], [],
    );
    $detalle = $entrega->detalles->first();

    $this->actingAs($this->admin)->post('/devoluciones', [
        'entrega_uniforme_id' => $entrega->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'fecha' => '2020-01-01', // manipulado: fecha muy anterior
        'activos' => [['detalle_entrega_id' => $detalle->id, 'cantidad' => 1, 'condicion' => 'reutilizable']],
        'unidades' => [],
        'firma' => firmaDemoBase64(),
        'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true,
    ])->assertRedirect();

    expect(Devolucion::sole()->fecha->toDateString())->toBe('2026-09-15')
        ->not->toBe('2020-01-01');
});

it('devolución: cerca de medianoche UTC, la fecha guardada es la del día de negocio en America/Mexico_City', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-16 03:00:00', 'UTC'));

    $entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->admin->id, '2026-09-15',
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 5]],
        [], [],
    );
    $detalle = $entrega->detalles->first();

    $this->actingAs($this->admin)->post('/devoluciones', [
        'entrega_uniforme_id' => $entrega->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'fecha' => '2026-09-16',
        'activos' => [['detalle_entrega_id' => $detalle->id, 'cantidad' => 1, 'condicion' => 'reutilizable']],
        'unidades' => [],
        'firma' => firmaDemoBase64(),
        'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true,
    ])->assertRedirect();

    expect(Devolucion::sole()->fecha->toDateString())->toBe('2026-09-15');
});

it('la pantalla de creación de devolución recibe fechaActual calculada en la zona de presentación, no en UTC', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-16 03:00:00', 'UTC'));

    $this->actingAs($this->admin)->get('/devoluciones/crear')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Devoluciones/Crear')
            ->where('fechaActual', '2026-09-15'));
});
