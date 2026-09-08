<?php

use App\Acciones\ConfirmarAcuseDevolucion;
use App\Acciones\CrearEntregaUniforme;
use App\Acciones\RegistrarDevolucion;
use App\Enums\EstadoDevolucion;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Mail\ComprobanteDevolucionMail;
use App\Models\AcuseDevolucion;
use App\Models\SaldoInventario;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Misma filosofía que el comprobante de entrega, aplicada a devoluciones: el
 * correo se envía cuando la devolución queda confirmada (ambas firmas +
 * acuse + inventario reingresado), a ambas partes, sin duplicados, con el
 * PDF adjunto y la referencia a la entrega de origen; un fallo de SMTP no
 * revierte el reingreso ni el acuse; una consulta posterior no reenvía.
 */
beforeEach(function () {
    Storage::fake('local');
    Cache::forget('acuse-devolucion:correo:1');
    Cache::forget('acuse-devolucion:correo:2');

    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    $this->admin->update(['email' => 'encargado.dev@empresa.test']);
    $this->datos['colaboradorA']->update(['correo' => 'colaborador.dev@empresa.test']);

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

    $this->devolucion = app(RegistrarDevolucion::class)->ejecutar(
        $this->entrega->id,
        $this->datos['almacenA']->id,
        now()->toDateString(),
        [['detalle_entrega_id' => $this->detalle->id, 'cantidad' => 3, 'condicion' => 'reutilizable']],
        [],
        $this->admin->id,
    );

    $this->confirmar = app(ConfirmarAcuseDevolucion::class);

    $this->confirmarDemo = fn (): AcuseDevolucion => $this->confirmar->ejecutar(
        $this->devolucion,
        firmaDemoBase64(),
        firmaDemoBase64(),
        true,
        $this->admin->id,
        '127.0.0.1',
        'PHPUnit',
    );
});

it('una devolución pendiente de firma NO dispara ningún correo', function () {
    Mail::fake();

    expect($this->devolucion->fresh()->estado)->toBe(EstadoDevolucion::PendienteFirma);

    Mail::assertNothingQueued();
});

it('al confirmar la devolución con ambas firmas envía el comprobante a AMBAS partes', function () {
    Mail::fake();

    ($this->confirmarDemo)();

    Mail::assertQueued(ComprobanteDevolucionMail::class, 1);
    Mail::assertQueued(
        ComprobanteDevolucionMail::class,
        fn (ComprobanteDevolucionMail $m): bool => $m->hasTo('encargado.dev@empresa.test')
    );
    Mail::assertQueued(
        ComprobanteDevolucionMail::class,
        fn (ComprobanteDevolucionMail $m): bool => $m->hasTo('colaborador.dev@empresa.test')
    );
});

it('el correo de devolución adjunta el PDF y referencia la entrega de origen', function () {
    Mail::fake();

    $acuse = ($this->confirmarDemo)();

    expect($acuse->tienePdf())->toBeTrue();

    Mail::assertQueued(
        ComprobanteDevolucionMail::class,
        fn (ComprobanteDevolucionMail $m): bool => count($m->attachments()) === 1
            && $m->folioEntregaOriginal === $this->entrega->folio
    );
});

it('si el colaborador NO tiene correo, sólo se notifica al encargado y no hay error', function () {
    Mail::fake();
    $this->devolucion->colaborador->update(['correo' => null]);
    $this->devolucion->load('colaborador');

    $acuse = ($this->confirmarDemo)();

    expect($acuse)->not->toBeNull()
        ->and($this->devolucion->fresh()->estado)->toBe(EstadoDevolucion::Confirmada);

    Mail::assertQueued(ComprobanteDevolucionMail::class, 1);
    Mail::assertQueued(
        ComprobanteDevolucionMail::class,
        fn (ComprobanteDevolucionMail $m): bool => $m->hasTo('encargado.dev@empresa.test') && ! $m->hasTo('colaborador.dev@empresa.test')
    );
});

it('no envía dos copias cuando ambas direcciones coinciden', function () {
    Mail::fake();
    $this->devolucion->registradaPor->update(['email' => 'unico@empresa.test']);
    $this->devolucion->colaborador->update(['correo' => 'UNICO@empresa.test']);
    $this->devolucion->load(['registradaPor', 'colaborador']);

    ($this->confirmarDemo)();

    Mail::assertQueued(ComprobanteDevolucionMail::class, 1);
    Mail::assertQueued(ComprobanteDevolucionMail::class, function (ComprobanteDevolucionMail $m): bool {
        return count($m->to) === 1 && $m->hasTo('unico@empresa.test');
    });
});

it('un fallo al enviar el correo NO revierte la devolución confirmada ni el reingreso de inventario', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP caído'));

    $acuse = ($this->confirmarDemo)();

    expect($acuse->folio)->toStartWith('ACD-')
        ->and($this->devolucion->fresh()->estado)->toBe(EstadoDevolucion::Confirmada)
        ->and((int) SaldoInventario::query()->where('activo_id', $this->datos['activoA']->id)->value('cantidad'))->toBe(13);

    Storage::disk('local')->assertExists($acuse->ruta_firma);
});

it('confirmar dos veces no vuelve a encolar el correo de devolución', function () {
    Mail::fake();

    ($this->confirmarDemo)();

    try {
        $this->confirmar->ejecutar($this->devolucion->fresh(), firmaDemoBase64(), firmaDemoBase64(), true, $this->admin->id, null, null);
    } catch (ExcepcionDeNegocioSimple) {
        // esperado
    }

    Mail::assertQueued(ComprobanteDevolucionMail::class, 1);
});

it('consultar o regenerar el PDF de la devolución NO reenvía el correo', function () {
    Mail::fake();

    $acuse = ($this->confirmarDemo)();
    Mail::assertQueued(ComprobanteDevolucionMail::class, 1);

    $this->actingAs($this->admin)->get("/acuses-devolucion/{$acuse->id}/pdf")->assertOk();
    $this->actingAs($this->admin)->post("/acuses-devolucion/{$acuse->id}/regenerar-pdf")->assertRedirect();

    Mail::assertQueued(ComprobanteDevolucionMail::class, 1);
});
