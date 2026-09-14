<?php

use App\Acciones\CrearEntregaUniforme;
use App\Enums\CategoriaDocumentoExpediente;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Models\Activo;
use App\Models\Colaborador;
use App\Models\DocumentoExpediente;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioExpediente;
use App\Servicios\ServicioInventario;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Identificación del COLABORADOR QUE DEVUELVE durante el WIZARD de una
 * devolución nueva (todavía no existe el registro `Devolucion`). Mismo
 * concepto de mínimo privilegio que en Entregas
 * (`App\Servicios\ServicioIdentidadColaborador`), pero resuelto SIEMPRE desde
 * la ENTREGA REAL que origina la devolución — nunca desde un colaborador_id
 * suelto — para que el endpoint no sirva como acceso lateral a la
 * identificación de cualquier colaborador de una empresa autorizada.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    // Encargado: puede registrar devoluciones pero NO tiene `expediente-descargar`.
    $this->encargado = usuarioCon(RolSistema::Encargado->value, [$this->datos['empresaA']]);
    $this->colaborador = $this->datos['colaboradorA'];

    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id,
        almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id,
        tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial,
        cantidad: 10,
    ));

    $this->entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->colaborador->id,
        $this->datos['almacenA']->id,
        $this->admin->id,
        now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 2]],
        [],
        [],
    );
});

it('un encargado autorizado ve que hay identificación disponible y puede consultarla, aunque no tenga acceso al expediente', function () {
    $documento = subirIdentificacion($this, $this->admin, $this->colaborador->id);

    $this->actingAs($this->encargado)
        ->get("/colaboradores/{$this->colaborador->id}/expediente/{$documento->id}/ver")
        ->assertForbidden();

    $this->actingAs($this->encargado)
        ->get("/devoluciones/documento-identidad/{$this->entrega->id}")
        ->assertOk()
        ->assertJson(['disponible' => true, 'previsualizable' => true]);

    $respuesta = $this->actingAs($this->encargado)
        ->get("/devoluciones/documento-identidad/{$this->entrega->id}/ver")
        ->assertOk()
        ->assertHeader('content-type', 'image/jpeg')
        ->assertHeader('x-content-type-options', 'nosniff');

    expect($respuesta->headers->get('content-disposition'))->toContain('inline')
        ->and($respuesta->headers->get('content-disposition'))->not->toContain('storage/');
});

it('si el colaborador no tiene identificación, el estado es vacío controlado (nunca 500)', function () {
    $this->actingAs($this->encargado)
        ->get("/devoluciones/documento-identidad/{$this->entrega->id}")
        ->assertOk()
        ->assertExactJson(['disponible' => false]);

    $this->actingAs($this->encargado)
        ->get("/devoluciones/documento-identidad/{$this->entrega->id}/ver")
        ->assertNotFound();
});

it('un usuario fuera del alcance de la empresa de la entrega recibe 403', function () {
    subirIdentificacion($this, $this->admin, $this->colaborador->id);
    $ajeno = usuarioCon(RolSistema::Encargado->value, [$this->datos['empresaB']]);

    $this->actingAs($ajeno)->get("/devoluciones/documento-identidad/{$this->entrega->id}")->assertForbidden();
    $this->actingAs($ajeno)->get("/devoluciones/documento-identidad/{$this->entrega->id}/ver")->assertForbidden();
});

it('ANTI-IDOR: no existe forma de pedir la identidad de un colaborador ajeno a la entrega — el endpoint sólo acepta el id de la ENTREGA, nunca un colaborador_id', function () {
    // Colaborador ajeno CON identificación, sin ninguna entrega asociada a él.
    $colaboradorAjeno = Colaborador::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->create();
    subirIdentificacion($this, $this->admin, $colaboradorAjeno->id);

    // El único endpoint disponible está keyado por ENTREGA: no hay ruta que
    // acepte `$colaboradorAjeno->id` directamente. Confirmamos que la
    // identidad devuelta para ESTA entrega siempre es la de su propio
    // colaborador, nunca la del ajeno, incluso si alguien intentara
    // reutilizar/adivinar ids de entregas de la misma empresa.
    $entregaAjena = app(CrearEntregaUniforme::class)->ejecutar(
        $colaboradorAjeno->id,
        $this->datos['almacenA']->id,
        $this->admin->id,
        now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 1]],
        [],
        [],
    );

    $this->actingAs($this->encargado);

    // La entrega del colaborador principal: sin identificación (nunca la del ajeno).
    $this->getJson("/devoluciones/documento-identidad/{$this->entrega->id}")
        ->assertOk()->assertJson(['disponible' => false]);

    // La entrega del colaborador ajeno: SÍ la suya, correctamente aislada por entrega.
    $this->getJson("/devoluciones/documento-identidad/{$entregaAjena->id}")
        ->assertOk()->assertJson(['disponible' => true]);
});

it('no se puede consultar la identidad de una entrega de otra empresa manipulando el id de la URL', function () {
    $colaboradorB = Colaborador::factory()->for($this->datos['empresaB'])->for($this->datos['sucursalB'])->create();
    $activoB = Activo::factory()->for($this->datos['empresaB'])->create(['nombre' => 'Casco']);
    $activoB->tallas()->attach($this->datos['tallaA']);
    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaB']->id,
        almacenId: $this->datos['almacenB']->id,
        activoId: $activoB->id,
        tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial,
        cantidad: 5,
    ));
    $entregaB = app(CrearEntregaUniforme::class)->ejecutar(
        $colaboradorB->id,
        $this->datos['almacenB']->id,
        $this->admin->id,
        now()->toDateString(),
        [['activo_id' => $activoB->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 1]],
        [],
        [],
    );

    // El encargado sólo tiene alcance a la empresa A.
    $this->actingAs($this->encargado)
        ->get("/devoluciones/documento-identidad/{$entregaB->id}/ver")
        ->assertForbidden();
});

it('sube una INE faltante desde el wizard de devolución y queda visible con la MISMA consulta que usa el módulo Expediente', function () {
    $this->actingAs($this->admin);

    $this->getJson("/devoluciones/documento-identidad/{$this->entrega->id}")
        ->assertOk()->assertJson(['disponible' => false]);

    $this->post(
        "/devoluciones/documento-identidad/{$this->entrega->id}",
        ['archivo' => UploadedFile::fake()->image('ine.jpg', 1000, 640)],
        ['Accept' => 'application/json'],
    )->assertOk()->assertJson(['ok' => true]);

    $documento = DocumentoExpediente::query()
        ->where('colaborador_id', $this->colaborador->id)
        ->where('categoria', CategoriaDocumentoExpediente::Identificacion)
        ->first();
    expect($documento)->not->toBeNull()
        ->and($documento->activo)->toBeTrue();

    $payload = app(ServicioExpediente::class)->payload($this->colaborador->fresh(), $this->admin, 'activos');
    expect(collect($payload['documentos'])->pluck('categoria'))->toContain('identificacion');

    $this->getJson("/devoluciones/documento-identidad/{$this->entrega->id}")
        ->assertJson(['disponible' => true]);
});

it('no crea una segunda identificación si el colaborador ya tiene una (subida directa al expediente)', function () {
    $this->actingAs($this->admin);

    $this->post("/devoluciones/documento-identidad/{$this->entrega->id}", ['archivo' => UploadedFile::fake()->image('ine.jpg')], ['Accept' => 'application/json'])->assertOk();
    $this->post("/devoluciones/documento-identidad/{$this->entrega->id}", ['archivo' => UploadedFile::fake()->image('ine2.jpg')], ['Accept' => 'application/json'])->assertStatus(422);

    expect(DocumentoExpediente::query()
        ->where('colaborador_id', $this->colaborador->id)
        ->where('categoria', CategoriaDocumentoExpediente::Identificacion)
        ->count())->toBe(1);
});

it('exige poder registrar devoluciones y acceso a la empresa de la entrega', function () {
    $encargadoAjeno = usuarioCon(RolSistema::Encargado->value, [$this->datos['empresaB']]);
    $this->actingAs($encargadoAjeno);
    $this->post("/devoluciones/documento-identidad/{$this->entrega->id}", ['archivo' => UploadedFile::fake()->image('ine.jpg')], ['Accept' => 'application/json'])
        ->assertForbidden();

    $colaboradorUser = usuarioCon(RolSistema::Colaborador->value, [$this->datos['empresaA']]);
    $this->actingAs($colaboradorUser);
    $this->post("/devoluciones/documento-identidad/{$this->entrega->id}", ['archivo' => UploadedFile::fake()->image('ine.jpg')], ['Accept' => 'application/json'])
        ->assertForbidden();
});
