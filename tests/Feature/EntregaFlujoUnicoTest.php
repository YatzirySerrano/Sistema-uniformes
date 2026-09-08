<?php

use App\Acciones\CrearEntregaUniforme;
use App\Enums\EstadoEntrega;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Mail\ComprobanteEntregaMail;
use App\Models\AcuseRecepcion;
use App\Models\EntregaUniforme;
use App\Models\SaldoInventario;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Flujo ÚNICO de entrega (registrar + firmar en una sola operación): una
 * entrega nueva NUNCA puede quedar registrada como "pendiente de firma"
 * esperando firma posterior. Sin las dos firmas + aceptación no se registra
 * ni descuenta nada; con ellas la entrega nace FIRMADA con acuse y correo.
 */
beforeEach(function () {
    Storage::fake('local');
    Mail::fake();

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

    $this->payload = fn (array $extra = []): array => [
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

it('una entrega nueva no puede finalizar sin la firma del colaborador', function () {
    $this->actingAs($this->admin)
        ->post('/entregas', ($this->payload)(['firma' => '']))
        ->assertSessionHasErrors(['firma' => 'Solicita la firma del colaborador para continuar.']);

    expect(EntregaUniforme::count())->toBe(0)
        ->and((int) SaldoInventario::query()->where('activo_id', $this->datos['activoA']->id)->value('cantidad'))->toBe(20);
    Mail::assertNothingQueued();
});

it('no puede finalizar sin la firma del encargado', function () {
    $this->actingAs($this->admin)
        ->post('/entregas', ($this->payload)(['firma_operador' => '']))
        ->assertSessionHasErrors(['firma_operador' => 'Falta la firma del encargado que realiza la entrega.']);

    expect(EntregaUniforme::count())->toBe(0);
});

it('no puede finalizar sin la aceptación', function () {
    $this->actingAs($this->admin)
        ->post('/entregas', ($this->payload)(['aceptacion' => false]))
        ->assertSessionHasErrors(['aceptacion' => 'Debes confirmar la aceptación antes de finalizar la entrega.']);

    expect(EntregaUniforme::count())->toBe(0);
});

it('con ambas firmas y la aceptación la entrega nace FIRMADA, con acuse, inventario descontado, PDF y correo', function () {
    $this->actingAs($this->admin)
        ->post('/entregas', ($this->payload)())
        ->assertRedirect();

    $entrega = EntregaUniforme::sole();

    expect($entrega->estado)->toBe(EstadoEntrega::Firmada)
        ->and($entrega->confirmada_en)->not->toBeNull()
        ->and($entrega->acuse)->not->toBeNull()
        ->and($entrega->acuse->folio)->toStartWith('ACU-')
        ->and((int) SaldoInventario::query()->where('activo_id', $this->datos['activoA']->id)->value('cantidad'))->toBe(18)
        // Ninguna entrega queda en "pendiente de firma" por el flujo nuevo.
        ->and(EntregaUniforme::query()->where('estado', EstadoEntrega::PendienteFirma)->count())->toBe(0);

    Storage::disk('local')->assertExists($entrega->acuse->ruta_firma);
    Storage::disk('local')->assertExists($entrega->acuse->ruta_firma_operador);
    Storage::disk('local')->assertExists($entrega->acuse->ruta_pdf);

    Mail::assertQueued(ComprobanteEntregaMail::class, 1);
});

it('una firma inválida no registra nada, no deja archivos huérfanos y no descuenta inventario (transacción atómica)', function () {
    $this->actingAs($this->admin)
        ->post('/entregas', ($this->payload)(['firma' => 'data:image/png;base64,AAAA']))
        ->assertSessionHasErrors('negocio');

    expect(EntregaUniforme::count())->toBe(0)
        ->and(AcuseRecepcion::count())->toBe(0)
        ->and((int) SaldoInventario::query()->where('activo_id', $this->datos['activoA']->id)->value('cantidad'))->toBe(20);

    Storage::disk('local')->assertDirectoryEmpty("firmas/{$this->datos['empresaA']->id}");
    Mail::assertNothingQueued();
});

it('un doble submit con la misma idempotency_key registra UNA sola entrega, acuse y correo', function () {
    $payload = ($this->payload)();

    $this->actingAs($this->admin)->post('/entregas', $payload)->assertRedirect();
    $this->actingAs($this->admin)->post('/entregas', $payload)
        ->assertSessionHasErrors('negocio');

    expect(EntregaUniforme::count())->toBe(1)
        ->and(AcuseRecepcion::count())->toBe(1);
    Mail::assertQueued(ComprobanteEntregaMail::class, 1);
});

it('la ruta histórica de firma diferida sigue funcionando para una entrega antigua en pendiente de firma', function () {
    // Entrega creada por la Action directamente (como los históricos): nace
    // PendienteFirma y se firma después por la ruta separada.
    $entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id,
        $this->datos['almacenA']->id,
        $this->admin->id,
        now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 1]],
        [],
        [],
    );

    expect($entrega->estado)->toBe(EstadoEntrega::PendienteFirma);

    $this->actingAs($this->admin)
        ->post("/entregas/{$entrega->id}/firmar", [
            'firma' => firmaDemoBase64(),
            'firma_operador' => firmaDemoBase64(),
            'aceptacion' => true,
        ])
        ->assertRedirect();

    expect($entrega->fresh()->estado)->toBe(EstadoEntrega::Firmada);
});

it('un usuario sin acceso a la empresa del colaborador recibe 403 y no crea nada', function () {
    $ajeno = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaB']]);

    $this->actingAs($ajeno)
        ->post('/entregas', ($this->payload)())
        ->assertForbidden();

    expect(EntregaUniforme::count())->toBe(0);
});
