<?php

use App\Acciones\RegistrarTraspasoInventario;
use App\Enums\EstadoUnidadActivo;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\EntregaUniforme;
use App\Models\MovimientoInventario;
use App\Models\Reserva;
use App\Models\SaldoInventario;
use App\Models\UnidadActivo;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioAcusePdf;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Ciclo de vida COMPLETO del apartado temporal vía HTTP (como lo usa el
 * formulario): reservar sólo bloquea disponibilidad lógica, liberar sólo
 * quita ese bloqueo, confirmar cambia el estado real UNA vez, y un token
 * cerrado (liberado o consumido) nunca se reactiva — la causa de los
 * apartados fantasma tras cancelar, abandonar o recargar.
 */
beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
    $this->mock(ServicioAcusePdf::class, function ($mock): void {
        $mock->shouldReceive('generar')->andReturn('acuses/fake.pdf');
        $mock->shouldReceive('contenido')->andReturn(null);
    });

    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    $this->otro = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    $this->almacenDestino = Almacen::factory()->paraEmpresa($this->datos['empresaA'])->create();

    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id, almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id, tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial, cantidad: 10,
    ));

    $this->fila = fn (int $cantidad): array => [
        'activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => $cantidad, 'finalidad' => 'uso_personal',
    ];
    $this->reservar = fn ($usuario, string $token, array $activos = [], array $unidades = []) => $this->actingAs($usuario)->postJson('/entregas/reserva', [
        'token' => $token, 'empresa_id' => $this->datos['empresaA']->id, 'almacen_id' => $this->datos['almacenA']->id,
        'colaborador_id' => $this->datos['colaboradorA']->id, 'activos' => $activos, 'unidades' => $unidades,
    ]);
    $this->liberar = fn ($usuario, string $token, string $base = '/entregas/reserva') => $this->actingAs($usuario)->deleteJson("{$base}/{$token}");
    /** Disponible que ve un usuario (con su propio token, como el formulario). */
    $this->disponible = function ($usuario, ?string $token = null, ?Almacen $almacen = null): int {
        $saldos = $this->actingAs($usuario)->getJson('/entregas/disponibilidad?'.http_build_query(array_filter([
            'empresa_id' => $this->datos['empresaA']->id, 'almacen_id' => ($almacen ?? $this->datos['almacenA'])->id, 'token' => $token,
        ])))->assertOk()->json('saldos');

        return (int) (collect($saldos)->firstWhere('activo_id', $this->datos['activoA']->id)['disponible'] ?? 0);
    };
    $this->saldo = fn (?Almacen $almacen = null): int => (int) SaldoInventario::query()
        ->where('almacen_id', ($almacen ?? $this->datos['almacenA'])->id)->where('activo_id', $this->datos['activoA']->id)->value('cantidad');
    $this->confirmarEntrega = fn (string $token, int $cantidad, ?string $clave = null) => $this->actingAs($this->admin)->post('/entregas', [
        'colaborador_id' => $this->datos['colaboradorA']->id, 'almacen_id' => $this->datos['almacenA']->id,
        'fecha_entrega' => now()->toDateString(), 'firma' => firmaDemoBase64(), 'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true, 'reserva_token' => $token, 'activos' => [($this->fila)($cantidad)],
        'idempotency_key' => $clave ?? (string) Str::uuid(),
    ]);
});

/*
|--------------------------------------------------------------------------
| Reserva base
|--------------------------------------------------------------------------
*/

it('reservar baja la disponibilidad lógica de otros, no el stock real, y nunca se descuenta a sí misma', function () {
    $token = (string) Str::uuid();
    ($this->reservar)($this->admin, $token, [($this->fila)(4)])->assertOk()->assertJsonPath('ok', true);

    expect(($this->saldo)())->toBe(10)
        ->and(MovimientoInventario::query()->count())->toBe(1)
        ->and(($this->disponible)($this->otro))->toBe(6)
        // El propio borrador ve 10 (no 10 − 4 − su selección).
        ->and(($this->disponible)($this->admin, $token))->toBe(10);
});

it('liberar restaura la disponibilidad sin tocar stock ni crear movimientos, y es idempotente', function () {
    $token = (string) Str::uuid();
    ($this->reservar)($this->admin, $token, [($this->fila)(4)])->assertOk();

    ($this->liberar)($this->admin, $token)->assertOk();
    ($this->liberar)($this->admin, $token)->assertOk();
    ($this->liberar)($this->admin, (string) Str::uuid())->assertOk(); // inexistente: no-op

    expect(($this->disponible)($this->otro))->toBe(10)
        ->and(($this->saldo)())->toBe(10)
        ->and(MovimientoInventario::query()->count())->toBe(1);
});

it('conocer el token no permite liberar la reserva de otro usuario ni la de otro tipo', function () {
    $token = (string) Str::uuid();
    ($this->reservar)($this->admin, $token, [($this->fila)(4)])->assertOk();

    ($this->liberar)($this->otro, $token)->assertOk();
    ($this->liberar)($this->admin, $token, '/inventario/traspasos/reserva')->assertOk();

    expect(Reserva::query()->where('token', $token)->sole()->estaActiva())->toBeTrue()
        ->and(($this->disponible)($this->otro))->toBe(6);
});

it('una reserva vencida no bloquea aunque la fila siga existiendo; la limpieza la borra sin tocar stock', function () {
    $token = (string) Str::uuid();
    ($this->reservar)($this->admin, $token, [($this->fila)(4)])->assertOk();
    $this->travel(11)->minutes();

    expect(Reserva::query()->where('token', $token)->exists())->toBeTrue()
        ->and(($this->disponible)($this->otro))->toBe(10);

    $this->travel(2)->hours();
    $this->artisan('reservas:limpiar')->assertSuccessful();

    expect(Reserva::query()->where('token', $token)->exists())->toBeFalse()
        ->and(($this->saldo)())->toBe(10)
        ->and(MovimientoInventario::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Entrega: editar, abandonar, confirmar
|--------------------------------------------------------------------------
*/

it('cambiar 5 → 2 libera 3 y quitar la línea libera todo, en el mismo borrador', function () {
    $token = (string) Str::uuid();
    ($this->reservar)($this->admin, $token, [($this->fila)(5)])->assertOk();
    expect(($this->disponible)($this->otro))->toBe(5);

    ($this->reservar)($this->admin, $token, [($this->fila)(2)])->assertOk();
    expect(($this->disponible)($this->otro))->toBe(8);

    ($this->reservar)($this->admin, $token, [])->assertOk();
    expect(($this->disponible)($this->otro))->toBe(10)
        ->and(Reserva::query()->count())->toBe(1);
});

it('un recálculo tardío (debounced o en vuelo) después de Cancelar/abandonar NO reabre el apartado', function () {
    $token = (string) Str::uuid();
    ($this->reservar)($this->admin, $token, [($this->fila)(4)])->assertOk();
    ($this->liberar)($this->admin, $token)->assertOk();

    ($this->reservar)($this->admin, $token, [($this->fila)(4)])->assertStatus(422);

    expect(Reserva::query()->where('token', $token)->sole()->liberada_en)->not->toBeNull()
        ->and(($this->disponible)($this->otro))->toBe(10);
});

it('recargar: el borrador nuevo (token nuevo) ve todo una vez liberado el anterior, sin duplicar apartados', function () {
    $anterior = (string) Str::uuid();
    ($this->reservar)($this->admin, $anterior, [($this->fila)(4)])->assertOk();

    // Mientras el anterior siga vivo, compite incluso con el mismo usuario
    // (así se veía el bug: "menos existencias" tras recargar).
    $nuevo = (string) Str::uuid();
    expect(($this->disponible)($this->admin, $nuevo))->toBe(6);

    // La recarga libera el anterior (pagehide o al reabrir la pestaña).
    ($this->liberar)($this->admin, $anterior)->assertOk();
    ($this->reservar)($this->admin, $nuevo, [($this->fila)(4)])->assertOk();

    expect(($this->disponible)($this->admin, $nuevo))->toBe(10)
        ->and(($this->disponible)($this->otro))->toBe(6)
        ->and(Reserva::query()->activa()->count())->toBe(1);
});

it('confirmar consume la reserva y descuenta stock UNA vez; limpieza o recálculo posteriores no la reviven ni devuelven stock', function () {
    $token = (string) Str::uuid();
    ($this->reservar)($this->admin, $token, [($this->fila)(4)])->assertOk();
    $clave = (string) Str::uuid();

    ($this->confirmarEntrega)($token, 4, $clave)->assertSessionHasNoErrors();
    // Reintento de red con la misma clave: no hay doble consumo.
    ($this->confirmarEntrega)($token, 4, $clave);

    // Limpieza tardía (desmontar/pagehide) y un recálculo en vuelo.
    ($this->liberar)($this->admin, $token)->assertOk();
    ($this->reservar)($this->admin, $token, [($this->fila)(4)])->assertStatus(422);

    $reserva = Reserva::query()->where('token', $token)->sole();
    expect(EntregaUniforme::query()->count())->toBe(1)
        ->and(($this->saldo)())->toBe(6)
        ->and($reserva->consumida_en)->not->toBeNull()
        ->and($reserva->liberada_en)->toBeNull()
        ->and($reserva->estaActiva())->toBeFalse()
        ->and(($this->disponible)($this->otro))->toBe(6);
});

it('una unidad individual apartada no la toma otro; al abandonar vuelve a estar disponible', function () {
    $laptop = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create();
    $unidad = UnidadActivo::factory()->for($this->datos['empresaA'])->for($laptop)->for($this->datos['almacenA'])
        ->create(['estado' => EstadoUnidadActivo::EnAlmacen]);
    $tokenA = (string) Str::uuid();
    $tokenB = (string) Str::uuid();

    ($this->reservar)($this->admin, $tokenA, [], [['unidad_activo_id' => $unidad->id]])->assertOk()->assertJsonPath('ok', true);
    ($this->reservar)($this->otro, $tokenB, [], [['unidad_activo_id' => $unidad->id]])->assertOk()->assertJsonPath('ok', false);

    ($this->liberar)($this->admin, $tokenA)->assertOk();

    ($this->reservar)($this->otro, $tokenB, [], [['unidad_activo_id' => $unidad->id]])->assertOk()->assertJsonPath('ok', true);
    expect($unidad->fresh()->estado)->toBe(EstadoUnidadActivo::EnAlmacen);
});

it('dos usuarios por las últimas existencias: sólo se aparta lo que alcanza y nunca hay disponibilidad negativa', function () {
    ($this->reservar)($this->admin, (string) Str::uuid(), [($this->fila)(7)])->assertOk()->assertJsonPath('ok', true);
    // Misma persona en otra pestaña: otro token, compite igual.
    ($this->reservar)($this->admin, (string) Str::uuid(), [($this->fila)(3)])->assertOk()->assertJsonPath('ok', true);
    ($this->reservar)($this->otro, (string) Str::uuid(), [($this->fila)(1)])->assertOk()->assertJsonPath('ok', false);

    expect(($this->disponible)($this->otro))->toBe(0)
        ->and(($this->saldo)())->toBe(10);
});

/*
|--------------------------------------------------------------------------
| Traspaso
|--------------------------------------------------------------------------
*/

it('traspaso: aparta sólo en el origen; abandonarlo no mueve stock; confirmar mueve origen → destino una vez', function () {
    $renglon = ['control' => 'cantidad', 'activo_origen_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 4];
    $reservarTraspaso = fn (string $token) => $this->actingAs($this->admin)->postJson('/inventario/traspasos/reserva', [
        'token' => $token, 'empresa_origen_id' => $this->datos['empresaA']->id, 'almacen_origen_id' => $this->datos['almacenA']->id, 'renglones' => [$renglon],
    ]);

    $abandonado = (string) Str::uuid();
    $reservarTraspaso($abandonado)->assertOk()->assertJsonPath('ok', true);
    expect(($this->disponible)($this->otro))->toBe(6)
        ->and(($this->disponible)($this->otro, null, $this->almacenDestino))->toBe(0)
        ->and(($this->saldo)($this->almacenDestino))->toBe(0);

    ($this->liberar)($this->admin, $abandonado, '/inventario/traspasos/reserva')->assertOk();
    expect(($this->disponible)($this->otro))->toBe(10)
        ->and(($this->saldo)())->toBe(10)
        ->and(MovimientoInventario::query()->count())->toBe(1);

    $token = (string) Str::uuid();
    $reservarTraspaso($token)->assertOk();
    app(RegistrarTraspasoInventario::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenA']->id,
        $this->datos['empresaA']->id, $this->almacenDestino->id,
        [$renglon], $this->admin->id, reservaToken: $token,
    );
    ($this->liberar)($this->admin, $token, '/inventario/traspasos/reserva')->assertOk();

    expect(($this->saldo)())->toBe(6)
        ->and(($this->saldo)($this->almacenDestino))->toBe(4)
        ->and(Reserva::query()->where('token', $token)->sole()->consumida_en)->not->toBeNull()
        ->and(Reserva::query()->activa()->count())->toBe(0);
});
