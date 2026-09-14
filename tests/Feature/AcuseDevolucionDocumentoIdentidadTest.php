<?php

use App\Acciones\CrearEntregaUniforme;
use App\Acciones\RegistrarDevolucion;
use App\Enums\CategoriaDocumentoExpediente;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Models\DocumentoExpediente;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Identificación del COLABORADOR QUE DEVUELVE durante la firma de una
 * devolución YA REGISTRADA (flujo legado `devoluciones/{devolucion}/firmar`,
 * ver `.ai/rules/devoluciones.md`). El colaborador se resuelve SIEMPRE desde
 * `Devolucion->colaborador` — nunca desde un id de la URL — así que aquí no
 * existe ni siquiera un parámetro de colaborador que manipular.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    $this->colaborador = $this->datos['colaboradorA'];

    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id,
        almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id,
        tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial,
        cantidad: 10,
    ));

    $entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->colaborador->id,
        $this->datos['almacenA']->id,
        $this->admin->id,
        now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 5]],
        [],
        [],
    );

    $this->devolucion = app(RegistrarDevolucion::class)->ejecutar(
        $entrega->id,
        $this->datos['almacenA']->id,
        now()->toDateString(),
        [['detalle_entrega_id' => $entrega->detalles->first()->id, 'cantidad' => 2, 'condicion' => 'reutilizable']],
        [],
        $this->admin->id,
    );
});

it('un operador autorizado ve la identificación del colaborador que devuelve durante la firma de la devolución', function () {
    subirIdentificacion($this, $this->admin, $this->colaborador->id);
    $operador = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaA']]);

    $this->actingAs($operador)
        ->get("/devoluciones/{$this->devolucion->id}/documento-identidad")
        ->assertOk()
        ->assertJson(['disponible' => true, 'previsualizable' => true]);

    $respuesta = $this->actingAs($operador)
        ->get("/devoluciones/{$this->devolucion->id}/documento-identidad/ver")
        ->assertOk()
        ->assertHeader('content-type', 'image/jpeg');

    expect($respuesta->headers->get('content-disposition'))->toContain('inline');
});

it('si el colaborador no tiene identificación, el estado es vacío controlado', function () {
    $operador = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaA']]);

    $this->actingAs($operador)
        ->get("/devoluciones/{$this->devolucion->id}/documento-identidad")
        ->assertOk()
        ->assertExactJson(['disponible' => false]);

    $this->actingAs($operador)
        ->get("/devoluciones/{$this->devolucion->id}/documento-identidad/ver")
        ->assertNotFound();
});

it('un usuario que no puede confirmar esa devolución recibe 403 (cross-company)', function () {
    subirIdentificacion($this, $this->admin, $this->colaborador->id);
    $ajeno = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaB']]);

    $this->actingAs($ajeno)->get("/devoluciones/{$this->devolucion->id}/documento-identidad")->assertForbidden();
    $this->actingAs($ajeno)->get("/devoluciones/{$this->devolucion->id}/documento-identidad/ver")->assertForbidden();
});

it('sube una INE faltante desde la firma de una devolución ya registrada y queda visible en el expediente', function () {
    $operador = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaA']]);

    $this->actingAs($operador)
        ->post(
            "/devoluciones/{$this->devolucion->id}/documento-identidad",
            ['archivo' => UploadedFile::fake()->image('ine.jpg', 1000, 640)],
            ['Accept' => 'application/json'],
        )
        ->assertOk()->assertJson(['ok' => true]);

    $documento = DocumentoExpediente::query()
        ->where('colaborador_id', $this->colaborador->id)
        ->where('categoria', CategoriaDocumentoExpediente::Identificacion)
        ->first();
    expect($documento)->not->toBeNull()->and($documento->activo)->toBeTrue();
});

it('no crea una segunda identificación si el colaborador ya tiene una', function () {
    subirIdentificacion($this, $this->admin, $this->colaborador->id);
    $operador = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaA']]);

    $this->actingAs($operador)
        ->post(
            "/devoluciones/{$this->devolucion->id}/documento-identidad",
            ['archivo' => UploadedFile::fake()->image('ine2.jpg')],
            ['Accept' => 'application/json'],
        )
        ->assertStatus(422);

    expect(DocumentoExpediente::query()
        ->where('colaborador_id', $this->colaborador->id)
        ->where('categoria', CategoriaDocumentoExpediente::Identificacion)
        ->count())->toBe(1);
});

it('el titular de la devolución puede consultar y capturar su PROPIA identificación', function () {
    $titular = usuarioCon(RolSistema::Colaborador->value, [$this->datos['empresaA']]);
    $this->colaborador->update(['usuario_id' => $titular->id]);

    $this->actingAs($titular)
        ->post(
            "/devoluciones/{$this->devolucion->id}/documento-identidad",
            ['archivo' => UploadedFile::fake()->image('ine.jpg')],
            ['Accept' => 'application/json'],
        )
        ->assertOk()->assertJson(['ok' => true]);
});

it('ANTI-IDOR: no existe ningún parámetro de colaborador manipulable — la identidad se resuelve SIEMPRE desde Devolucion->colaborador', function () {
    subirIdentificacion($this, $this->admin, $this->colaborador->id);

    // Sanidad de la ruta: no acepta un segundo segmento con un id de
    // colaborador (el modelo vinculado es siempre la Devolución).
    $rutas = collect(app('router')->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'devoluciones/{devolucion}/documento-identidad'));

    expect($rutas)->not->toBeEmpty();
    foreach ($rutas as $ruta) {
        expect($ruta->parameterNames())->toBe(['devolucion']);
    }
});
