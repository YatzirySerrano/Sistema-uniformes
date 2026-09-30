<?php

use App\Acciones\RegistrarTraspasoInventario;
use App\Acciones\ReservarInventarioEntrega;
use App\Acciones\ReservarInventarioTraspaso;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Reserva;
use App\Models\SaldoInventario;
use App\Models\UnidadActivo;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Apartado temporal (10 min) de un traspaso: reduce la disponibilidad
 * efectiva para otros usuarios (entregas y traspasos del mismo almacén),
 * vence solo, se consume al confirmar y nunca reemplaza los candados finales.
 */
beforeEach(function () {
    Storage::fake('local');

    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    $this->otro = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    $this->almacenA2 = Almacen::factory()->paraEmpresa($this->datos['empresaA'])->create();

    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id, almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id, tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial, cantidad: 6,
    ));

    $this->renglon = fn (int $cantidad): array => [
        'control' => 'cantidad', 'activo_origen_id' => $this->datos['activoA']->id,
        'talla_id' => $this->datos['tallaA']->id, 'cantidad' => $cantidad,
    ];

    $this->reservar = fn (string $token, int $userId, array $renglones): array => app(ReservarInventarioTraspaso::class)->ejecutar(
        $token, $userId, $this->datos['empresaA']->id, $this->datos['almacenA']->id, $renglones,
    );

    $this->traspasar = fn (array $renglones, int $userId, ?string $token = null) => app(RegistrarTraspasoInventario::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenA']->id,
        $this->datos['empresaA']->id, $this->almacenA2->id,
        $renglones, $userId, reservaToken: $token,
    );

    $this->saldoOrigen = fn (): int => (int) SaldoInventario::query()
        ->where('almacen_id', $this->datos['almacenA']->id)
        ->where('activo_id', $this->datos['activoA']->id)
        ->value('cantidad');
});

it('reserva durante 10 minutos y reduce la disponibilidad efectiva de otros usuarios', function () {
    $tokenA = (string) Str::uuid();
    expect(($this->reservar)($tokenA, $this->admin->id, [($this->renglon)(5)])['ok'])->toBeTrue()
        ->and(Reserva::query()->where('token', $tokenA)->value('expira_en'))->toEqual(now()->addMinutes(10)->startOfSecond());

    $b = ($this->reservar)((string) Str::uuid(), $this->otro->id, [($this->renglon)(2)]);

    expect($b['ok'])->toBeFalse()
        ->and($b['lineas_cantidad'][0]['disponible_efectivo'])->toBe(1);
})->freezeTime();

it('entregas y traspasos del mismo almacén compiten por la misma existencia', function () {
    ($this->reservar)((string) Str::uuid(), $this->admin->id, [($this->renglon)(6)]);

    $entrega = app(ReservarInventarioEntrega::class)->ejecutar(
        (string) Str::uuid(), $this->otro->id, $this->datos['empresaA']->id, $this->datos['almacenA']->id, null,
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 1]], [], [],
    );

    expect($entrega['ok'])->toBeFalse();
});

it('al vencer el apartado la existencia vuelve a estar disponible', function () {
    ($this->reservar)((string) Str::uuid(), $this->admin->id, [($this->renglon)(6)]);

    $this->travel(11)->minutes();

    expect(($this->reservar)((string) Str::uuid(), $this->otro->id, [($this->renglon)(6)])['ok'])->toBeTrue();
});

it('confirmar consume el apartado y mueve el stock real; uno vencido no permite confirmar', function () {
    $token = (string) Str::uuid();
    ($this->reservar)($token, $this->admin->id, [($this->renglon)(4)]);

    ($this->traspasar)([($this->renglon)(4)], $this->admin->id, $token);

    expect(Reserva::query()->where('token', $token)->value('consumida_en'))->not->toBeNull()
        ->and(($this->saldoOrigen)())->toBe(2);

    $vencido = (string) Str::uuid();
    ($this->reservar)($vencido, $this->admin->id, [($this->renglon)(1)]);
    $this->travel(11)->minutes();

    expect(fn () => ($this->traspasar)([($this->renglon)(1)], $this->admin->id, $vencido))
        ->toThrow(ExcepcionDeNegocioSimple::class, 'Tu reserva venció');
    expect(($this->saldoOrigen)())->toBe(2);
});

it('liberar el apartado lo deja disponible de inmediato para otros', function () {
    $token = (string) Str::uuid();
    ($this->reservar)($token, $this->admin->id, [($this->renglon)(6)]);

    $this->actingAs($this->admin)->deleteJson("/inventario/traspasos/reserva/{$token}")->assertOk();

    expect(($this->reservar)((string) Str::uuid(), $this->otro->id, [($this->renglon)(6)])['ok'])->toBeTrue();
});

it('una unidad apartada por otro usuario no puede apartarse ni traspasarse dos veces', function () {
    $radio = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create();
    $unidad = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($radio)->for($this->datos['almacenA'])->create();
    $renglonUnidad = ['control' => 'individual', 'activo_origen_id' => $radio->id, 'unidad_ids' => [$unidad->id]];

    expect(($this->reservar)((string) Str::uuid(), $this->admin->id, [$renglonUnidad])['ok'])->toBeTrue();
    expect(($this->reservar)((string) Str::uuid(), $this->otro->id, [$renglonUnidad])['lineas_unidad'][0]['ok'])->toBeFalse();

    ($this->traspasar)([$renglonUnidad], $this->admin->id);

    expect(fn () => ($this->traspasar)([$renglonUnidad], $this->otro->id))
        ->toThrow(ExcepcionDeNegocioSimple::class);
    expect($unidad->fresh()->almacen_id)->toBe($this->almacenA2->id);
});

it('aunque falle o no exista el apartado, los candados finales impiden exceder la existencia real', function () {
    ($this->traspasar)([($this->renglon)(4)], $this->admin->id);

    expect(fn () => ($this->traspasar)([($this->renglon)(4)], $this->otro->id))
        ->toThrow(ExcepcionDeNegocioSimple::class);
    expect(($this->saldoOrigen)())->toBe(2);
});

it('el endpoint de apartado exige permiso de traspasos y acceso a la empresa origen', function () {
    $sinPermiso = usuarioCon(RolSistema::Encargado->value, [$this->datos['empresaA']]);

    $this->actingAs($sinPermiso)->postJson('/inventario/traspasos/reserva', [
        'token' => (string) Str::uuid(),
        'empresa_origen_id' => $this->datos['empresaA']->id,
        'almacen_origen_id' => $this->datos['almacenA']->id,
        'renglones' => [($this->renglon)(1)],
    ])->assertForbidden();

    $this->actingAs($this->admin)->postJson('/inventario/traspasos/reserva', [
        'token' => (string) Str::uuid(),
        'empresa_origen_id' => $this->datos['empresaA']->id,
        'almacen_origen_id' => $this->datos['almacenA']->id,
        'renglones' => [($this->renglon)(2)],
    ])->assertOk()->assertJsonPath('ok', true);
});
