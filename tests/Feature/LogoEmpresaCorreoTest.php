<?php

use App\Acciones\ConfirmarAcuseDevolucion;
use App\Acciones\ConfirmarAcuseRecepcion;
use App\Acciones\CrearEntregaUniforme;
use App\Acciones\RegistrarDevolucion;
use App\Enums\TipoMovimiento;
use App\Mail\ComprobanteDevolucionMail;
use App\Mail\ComprobanteEntregaMail;
use App\Models\User;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use App\Soporte\LogoEmpresaCorreo;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;

/**
 * Logo de empresa en correos (comprobantes de entrega/devolución):
 * - Con logo PNG/JPG/GIF existente → se adjunta INLINE vía Content-ID
 *   (`$message->embedData()`, API real de `Illuminate\Mail\Message` /
 *   Symfony Mailer) — nunca data URI ni URL remota.
 * - Sin logo, archivo faltante, o SVG (formato de logo válido para subir y
 *   para el PDF/Excel, pero no soportado de forma confiable embebido en
 *   correo — Outlook de escritorio no lo renderiza) → `resolver()` devuelve
 *   `null`, y el layout del correo pinta un placeholder con la inicial de la
 *   empresa en vez de dejar un hueco o un ícono roto.
 */
beforeEach(function () {
    Storage::fake('public');
});

// ---------------------------------------------------------------------------
// Unit: LogoEmpresaCorreo::resolver()
// ---------------------------------------------------------------------------

it('devuelve null cuando no hay ruta de logo', function () {
    expect(LogoEmpresaCorreo::resolver(null))->toBeNull();
});

it('devuelve null cuando el archivo no existe en el disco', function () {
    expect(LogoEmpresaCorreo::resolver('logos/no-existe.png'))->toBeNull();
});

it('devuelve null para SVG aunque el archivo exista (no embebible de forma confiable en correo)', function () {
    Storage::disk('public')->put('logos/empresa.svg', '<svg></svg>');

    expect(LogoEmpresaCorreo::resolver('logos/empresa.svg'))->toBeNull();
});

it('resuelve un PNG existente con su binario, mime y nombre de archivo', function () {
    Storage::disk('public')->put('logos/empresa.png', 'contenido-png-de-prueba');

    $logo = LogoEmpresaCorreo::resolver('logos/empresa.png');

    expect($logo)->not->toBeNull()
        ->and($logo['binario'])->toBe('contenido-png-de-prueba')
        ->and($logo['mime'])->toBe('image/png')
        ->and($logo['nombre'])->toBe('logo-empresa.png');
});

// ---------------------------------------------------------------------------
// Mailables reales (Section Q): no basta probar el helper aislado. Se
// dispara el Mailable real (cola `sync` en tests) y se inspecciona el
// mensaje Symfony realmente construido, vía el transporte `array`
// (`MAIL_MAILER=array` en phpunit.xml) — nunca `Mail::fake()`, que no
// construye el mensaje final y no permite verificar el CID real.
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->datos = escenarioMultiempresa();
    $this->encargado = User::factory()->create(['email' => 'encargado@empresa.test']);
    $this->datos['colaboradorA']->update(['correo' => 'colaborador@empresa.test']);

    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id,
        almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id,
        tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial,
        cantidad: 20,
    ));
});

/**
 * Envía un Mailable por el pipeline REAL (sin `Mail::fake()`) y devuelve el
 * mensaje Symfony realmente ensamblado, leído del transporte `array`.
 */
function ultimoMensajeSymfonyReal(Mailable $mailable): Email
{
    Mail::to('destinatario@empresa.test')->send($mailable);

    $transporte = app('mailer')->getSymfonyTransport();
    $enviado = $transporte->messages()->last();

    return $enviado->getOriginalMessage();
}

it('ComprobanteEntregaMail incrusta el logo PNG inline vía Content-ID cuando la empresa lo tiene', function () {
    Storage::disk('public')->put('logos/empresa-a.png', 'contenido-png-de-prueba');
    $this->datos['empresaA']->update(['logo_ruta' => 'logos/empresa-a.png']);

    $entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->encargado->id,
        now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 1]],
        [], [],
    );
    $acuse = app(ConfirmarAcuseRecepcion::class)->ejecutar(
        $entrega, firmaDemoBase64(), firmaDemoBase64(), true, $this->encargado->id, '127.0.0.1', 'PHPUnit',
    );

    $mensaje = ultimoMensajeSymfonyReal(new ComprobanteEntregaMail($acuse));

    // El HTML referencia el logo por cid:, nunca por data URI; el mensaje
    // Symfony final trae al menos una parte (el logo embebido inline — el
    // PDF del acuse aún no existe en este punto porque se materializa
    // después del commit, fuera de este flujo síncrono de prueba).
    expect($mensaje->getHtmlBody())->toContain('cid:')
        ->and($mensaje->getHtmlBody())->not->toContain('data:image')
        ->and(count($mensaje->getAttachments()))->toBeGreaterThan(0);
});

it('sin logo configurado, el correo NO incrusta imagen inline y muestra el placeholder', function () {
    $entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->encargado->id,
        now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 1]],
        [], [],
    );
    $acuse = app(ConfirmarAcuseRecepcion::class)->ejecutar(
        $entrega, firmaDemoBase64(), firmaDemoBase64(), true, $this->encargado->id, '127.0.0.1', 'PHPUnit',
    );

    $mensaje = ultimoMensajeSymfonyReal(new ComprobanteEntregaMail($acuse));

    expect($mensaje->getHtmlBody())->not->toContain('cid:')
        ->and($mensaje->getHtmlBody())->not->toContain('data:image')
        ->and(preg_match('/rgba\(255,255,255,0\.18\)/', $mensaje->getHtmlBody()))->toBe(1);
});

it('archivo de logo faltante en disco cae al placeholder sin romper el correo', function () {
    $this->datos['empresaA']->update(['logo_ruta' => 'logos/no-existe.png']);

    $entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->encargado->id,
        now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 1]],
        [], [],
    );
    $acuse = app(ConfirmarAcuseRecepcion::class)->ejecutar(
        $entrega, firmaDemoBase64(), firmaDemoBase64(), true, $this->encargado->id, '127.0.0.1', 'PHPUnit',
    );

    $mensaje = ultimoMensajeSymfonyReal(new ComprobanteEntregaMail($acuse));

    expect($mensaje->getHtmlBody())->not->toContain('cid:')
        ->and(preg_match('/rgba\(255,255,255,0\.18\)/', $mensaje->getHtmlBody()))->toBe(1);
});

it('logo SVG cae al placeholder (no soportado de forma confiable en correo)', function () {
    Storage::disk('public')->put('logos/empresa-a.svg', '<svg></svg>');
    $this->datos['empresaA']->update(['logo_ruta' => 'logos/empresa-a.svg']);

    $entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->encargado->id,
        now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 1]],
        [], [],
    );
    $acuse = app(ConfirmarAcuseRecepcion::class)->ejecutar(
        $entrega, firmaDemoBase64(), firmaDemoBase64(), true, $this->encargado->id, '127.0.0.1', 'PHPUnit',
    );

    $mensaje = ultimoMensajeSymfonyReal(new ComprobanteEntregaMail($acuse));

    expect($mensaje->getHtmlBody())->not->toContain('cid:')
        ->and(preg_match('/rgba\(255,255,255,0\.18\)/', $mensaje->getHtmlBody()))->toBe(1);
});

it('ComprobanteDevolucionMail comparte el mismo mecanismo de logo inline por CID', function () {
    Storage::disk('public')->put('logos/empresa-a.png', 'contenido-png-de-prueba');
    $this->datos['empresaA']->update(['logo_ruta' => 'logos/empresa-a.png']);

    $entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->encargado->id,
        now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 2]],
        [], [],
    );
    app(ConfirmarAcuseRecepcion::class)->ejecutar(
        $entrega, firmaDemoBase64(), firmaDemoBase64(), true, $this->encargado->id, '127.0.0.1', 'PHPUnit',
    );

    $devolucion = app(RegistrarDevolucion::class)->ejecutar(
        $entrega->id,
        $this->datos['almacenA']->id,
        now()->toDateString(),
        [['detalle_entrega_id' => $entrega->detalles->first()->id, 'cantidad' => 1, 'condicion' => 'reutilizable']],
        [],
        $this->encargado->id,
    );

    $acuseDevolucion = app(ConfirmarAcuseDevolucion::class)->ejecutar(
        $devolucion, firmaDemoBase64(), firmaDemoBase64(), true, $this->encargado->id, '127.0.0.1', 'PHPUnit',
    );

    $mensaje = ultimoMensajeSymfonyReal(new ComprobanteDevolucionMail($acuseDevolucion));

    expect($mensaje->getHtmlBody())->toContain('cid:')
        ->and($mensaje->getHtmlBody())->not->toContain('data:image');
});
