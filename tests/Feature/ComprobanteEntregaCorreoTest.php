<?php

use App\Acciones\ConfirmarAcuseRecepcion;
use App\Acciones\CrearEntregaUniforme;
use App\Enums\EstadoEntrega;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Excepciones\EntregaYaFirmadaException;
use App\Mail\ComprobanteEntregaMail;
use App\Models\AcuseRecepcion;
use App\Models\SaldoInventario;
use App\Models\User;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Sección 1 / 3 / 6 / 7 del QA: el comprobante de una entrega se envía sólo
 * cuando la operación documental está CONCLUIDA (ambas firmas + acuse +
 * entrega confirmada), a ambas partes, sin duplicados, con el PDF adjunto,
 * y un fallo de correo NUNCA revierte la operación ni se reenvía por
 * consultas posteriores.
 */
beforeEach(function () {
    Storage::fake('local');
    // El candado de idempotencia del correo vive en caché (clave por id de
    // acuse). En pruebas, SQLite `:memory:` reinicia el autoincremento en
    // cada test, así que el mismo id de acuse se reutiliza — se borra esa
    // clave entre pruebas para que el candado no bloquee un envío legítimo
    // (sin `Cache::flush()`, que además tiraría la caché de permisos de Spatie).
    Cache::forget('acuse-recepcion:correo:1');
    Cache::forget('acuse-recepcion:correo:2');

    $this->datos = escenarioMultiempresa();

    // El encargado que REALIZA la entrega.
    $this->encargado = User::factory()->create(['email' => 'encargado@empresa.test']);
    // El colaborador que RECIBE la entrega, con correo registrado.
    $this->datos['colaboradorA']->update(['correo' => 'colaborador@empresa.test']);

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
        $this->encargado->id,
        now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 2]],
        [],
        [],
    );

    $this->confirmar = app(ConfirmarAcuseRecepcion::class);

    $this->confirmarDemo = fn (): AcuseRecepcion => $this->confirmar->ejecutar(
        $this->entrega,
        firmaDemoBase64(),
        firmaDemoBase64(),
        true,
        $this->encargado->id,
        '127.0.0.1',
        'PHPUnit',
    );
});

it('crear una entrega (que nace pendiente de firma) NO dispara ningún correo', function () {
    Mail::fake();

    $otra = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id,
        $this->datos['almacenA']->id,
        $this->encargado->id,
        now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 1]],
        [],
        [],
    );

    expect($otra->estado)->toBe(EstadoEntrega::PendienteFirma);

    Mail::assertNothingQueued();
});

it('al confirmar con ambas firmas envía el comprobante a AMBAS partes', function () {
    Mail::fake();

    ($this->confirmarDemo)();

    Mail::assertQueued(ComprobanteEntregaMail::class, 1);
    Mail::assertQueued(
        ComprobanteEntregaMail::class,
        fn (ComprobanteEntregaMail $m): bool => $m->hasTo('encargado@empresa.test')
    );
    Mail::assertQueued(
        ComprobanteEntregaMail::class,
        fn (ComprobanteEntregaMail $m): bool => $m->hasTo('colaborador@empresa.test')
    );
});

it('el correo lleva el PDF del acuse adjunto cuando ya se materializó', function () {
    Mail::fake();

    $acuse = ($this->confirmarDemo)();

    expect($acuse->tienePdf())->toBeTrue();

    Mail::assertQueued(
        ComprobanteEntregaMail::class,
        fn (ComprobanteEntregaMail $m): bool => count($m->attachments()) === 1
    );
});

it('si el colaborador NO tiene correo, sólo se notifica al encargado y no hay error', function () {
    Mail::fake();
    $this->entrega->colaborador->update(['correo' => null]);
    $this->entrega->load('colaborador');

    $acuse = ($this->confirmarDemo)();

    expect($acuse)->not->toBeNull()
        ->and($this->entrega->fresh()->estado)->toBe(EstadoEntrega::Firmada);

    Mail::assertQueued(ComprobanteEntregaMail::class, 1);
    Mail::assertQueued(
        ComprobanteEntregaMail::class,
        fn (ComprobanteEntregaMail $m): bool => $m->hasTo('encargado@empresa.test') && ! $m->hasTo('colaborador@empresa.test')
    );
});

it('no envía dos copias cuando el encargado y el colaborador comparten la misma dirección', function () {
    Mail::fake();
    $this->encargado->update(['email' => 'misma@empresa.test']);
    $this->entrega->encargado->update(['email' => 'misma@empresa.test']);
    $this->entrega->colaborador->update(['correo' => 'MISMA@empresa.test']);
    $this->entrega->load(['encargado', 'colaborador']);

    ($this->confirmarDemo)();

    Mail::assertQueued(ComprobanteEntregaMail::class, 1);
    Mail::assertQueued(ComprobanteEntregaMail::class, function (ComprobanteEntregaMail $m): bool {
        return count($m->to) === 1 && $m->hasTo('misma@empresa.test');
    });
});

it('un fallo al enviar/encolar el correo NO revierte la entrega firmada ni el inventario', function () {
    // Sin Mail::fake(): forzamos que el despacho lance una excepción.
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP caído'));

    $acuse = ($this->confirmarDemo)();

    expect($acuse->folio)->toStartWith('ACU-')
        ->and($this->entrega->fresh()->estado)->toBe(EstadoEntrega::Firmada)
        ->and($acuse->fresh()->hash_documento)->toHaveLength(64)
        ->and((int) SaldoInventario::query()->where('activo_id', $this->datos['activoA']->id)->value('cantidad'))->toBe(18);

    Storage::disk('local')->assertExists($acuse->ruta_firma);
});

it('confirmar dos veces no vuelve a encolar el correo', function () {
    Mail::fake();

    ($this->confirmarDemo)();

    expect(fn () => $this->confirmar->ejecutar($this->entrega->fresh(), firmaDemoBase64(), firmaDemoBase64(), true, $this->encargado->id, null, null))
        ->toThrow(EntregaYaFirmadaException::class);

    Mail::assertQueued(ComprobanteEntregaMail::class, 1);
});

it('consultar el detalle, descargar el PDF o regenerarlo NO reenvía el correo', function () {
    Mail::fake();

    $acuse = ($this->confirmarDemo)();
    Mail::assertQueued(ComprobanteEntregaMail::class, 1);

    $operador = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);

    $this->actingAs($operador)->get("/entregas/{$this->entrega->id}")->assertOk();
    $this->actingAs($operador)->get("/acuses/{$acuse->id}/pdf")->assertOk();
    $this->actingAs($operador)->post("/acuses/{$acuse->id}/regenerar-pdf")->assertRedirect();

    Mail::assertQueued(ComprobanteEntregaMail::class, 1);
});
