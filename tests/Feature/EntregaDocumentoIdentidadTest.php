<?php

use App\Acciones\ConfirmarAcuseRecepcion;
use App\Acciones\CrearEntregaUniforme;
use App\Enums\CategoriaDocumentoExpediente;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Mail\ComprobanteEntregaMail;
use App\Models\Colaborador;
use App\Models\DocumentoExpediente;
use App\Models\User;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sube un documento de identidad (categoría `Identificacion`) al expediente
 * del colaborador, como administrador. Devuelve el `DocumentoExpediente`.
 */
function subirIdentificacion(TestCase $test, User $admin, int $colaboradorId, string $archivo = 'ine.jpg'): DocumentoExpediente
{
    $upload = str_ends_with($archivo, '.pdf')
        ? UploadedFile::fake()->create($archivo, 120, 'application/pdf')
        : UploadedFile::fake()->image($archivo);

    $test->actingAs($admin)->post("/colaboradores/{$colaboradorId}/expediente", [
        'categoria' => CategoriaDocumentoExpediente::Identificacion->value,
        'nombre' => 'INE',
        'archivo' => $upload,
    ])->assertRedirect();

    return DocumentoExpediente::query()->where('colaborador_id', $colaboradorId)->latest('id')->firstOrFail();
}

/**
 * Consulta del documento de identidad (categoría `Identificacion` del
 * expediente) durante el flujo de firma de una entrega. Finalidad única:
 * que el encargado tenga el documento a la mano para una verificación
 * VISUAL propia. Sin OCR, sin biometría, sin comparación automática. El
 * documento nunca se adjunta al acuse ni al correo, y el acceso es de
 * mínimo privilegio (poder registrar entregas del colaborador), no acceso
 * completo al expediente.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    // Encargado: puede registrar entregas pero NO tiene `expediente-descargar`.
    $this->encargado = usuarioCon(RolSistema::Encargado->value, [$this->datos['empresaA']]);
    $this->colaborador = $this->datos['colaboradorA'];
});

it('un encargado autorizado ve que hay identificación disponible y puede consultarla, aunque no tenga acceso al expediente', function () {
    $documento = subirIdentificacion($this, $this->admin, $this->colaborador->id);

    // El endpoint del expediente le está vedado (necesita `expediente-descargar`).
    $this->actingAs($this->encargado)
        ->get("/colaboradores/{$this->colaborador->id}/expediente/{$documento->id}/ver")
        ->assertForbidden();

    // El del flujo de entrega, no: basta poder registrar entregas del colaborador.
    $this->actingAs($this->encargado)
        ->get("/entregas/documento-identidad/{$this->colaborador->id}")
        ->assertOk()
        ->assertJson(['disponible' => true, 'previsualizable' => true]);

    $respuesta = $this->actingAs($this->encargado)
        ->get("/entregas/documento-identidad/{$this->colaborador->id}/ver")
        ->assertOk()
        ->assertHeader('content-type', 'image/jpeg')
        ->assertHeader('x-content-type-options', 'nosniff');

    expect($respuesta->headers->get('content-disposition'))->toContain('inline')
        ->and($respuesta->headers->get('content-disposition'))->not->toContain('storage/');
});

it('sirve SIEMPRE la última versión del documento de identidad', function () {
    $documento = subirIdentificacion($this, $this->admin, $this->colaborador->id);

    $this->actingAs($this->admin)->post("/colaboradores/{$this->colaborador->id}/expediente/{$documento->id}/version", [
        'archivo' => UploadedFile::fake()->image('ine-v2.png'),
    ])->assertRedirect();

    $documento->refresh();
    expect($documento->versionActual->version)->toBe(2);

    $this->actingAs($this->encargado)
        ->get("/entregas/documento-identidad/{$this->colaborador->id}")
        ->assertOk()
        ->assertJson(['disponible' => true, 'mime' => 'image/png']); // v2 es png
});

it('si el colaborador no tiene identificación, el estado es vacío controlado (nunca 500)', function () {
    $this->actingAs($this->encargado)
        ->get("/entregas/documento-identidad/{$this->colaborador->id}")
        ->assertOk()
        ->assertExactJson(['disponible' => false]);

    $this->actingAs($this->encargado)
        ->get("/entregas/documento-identidad/{$this->colaborador->id}/ver")
        ->assertNotFound();
});

it('un documento de identidad eliminado no se ofrece por este flujo', function () {
    $documento = subirIdentificacion($this, $this->admin, $this->colaborador->id);
    $this->actingAs($this->admin)->post("/colaboradores/{$this->colaborador->id}/expediente/{$documento->id}/estado")->assertRedirect();

    $this->actingAs($this->encargado)
        ->get("/entregas/documento-identidad/{$this->colaborador->id}")
        ->assertOk()
        ->assertExactJson(['disponible' => false]);
});

it('un usuario fuera del alcance de la empresa del colaborador recibe 403', function () {
    subirIdentificacion($this, $this->admin, $this->colaborador->id);
    $ajeno = usuarioCon(RolSistema::Encargado->value, [$this->datos['empresaB']]);

    $this->actingAs($ajeno)->get("/entregas/documento-identidad/{$this->colaborador->id}")->assertForbidden();
    $this->actingAs($ajeno)->get("/entregas/documento-identidad/{$this->colaborador->id}/ver")->assertForbidden();
});

it('no se puede consultar la identificación de otro colaborador manipulando el id de la URL', function () {
    subirIdentificacion($this, $this->admin, $this->colaborador->id);
    $colaboradorAjeno = Colaborador::factory()->for($this->datos['empresaB'])->for($this->datos['sucursalB'])->create();

    // El encargado sólo tiene alcance a la empresa A.
    $this->actingAs($this->encargado)
        ->get("/entregas/documento-identidad/{$colaboradorAjeno->id}/ver")
        ->assertForbidden();
});

it('abrir el preview del INE no modifica el expediente', function () {
    $documento = subirIdentificacion($this, $this->admin, $this->colaborador->id);
    $versionAntes = $documento->versionActual;
    $actualizadoAntes = $documento->updated_at;
    $conteoVersiones = $documento->versiones()->count();

    $this->actingAs($this->encargado)->get("/entregas/documento-identidad/{$this->colaborador->id}/ver")->assertOk();

    $documento->refresh();
    expect($documento->updated_at->equalTo($actualizadoAntes))->toBeTrue()
        ->and($documento->versiones()->count())->toBe($conteoVersiones)
        ->and($documento->versionActual->id)->toBe($versionAntes->id)
        ->and($documento->versionActual->updated_at->equalTo($versionAntes->updated_at))->toBeTrue();
});

it('el documento de identidad NO se incorpora al acuse, al snapshot ni al correo de la entrega', function () {
    Mail::fake();
    $documento = subirIdentificacion($this, $this->admin, $this->colaborador->id);

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
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 1]],
        [],
        [],
    );

    $acuse = app(ConfirmarAcuseRecepcion::class)->ejecutar(
        $entrega, firmaDemoBase64(), firmaDemoBase64(), true, $this->admin->id, null, null,
    );

    $snapshotJson = json_encode($acuse->snapshot_entrega);
    $rutaVersion = $documento->versionActual->ruta;

    expect($snapshotJson)->not->toContain('identificacion')
        ->and($snapshotJson)->not->toContain($rutaVersion)
        ->and($snapshotJson)->not->toContain('documento_expediente');

    // El único adjunto del correo es el PDF del acuse, nunca el INE.
    Mail::assertQueued(ComprobanteEntregaMail::class, function (ComprobanteEntregaMail $m) use ($rutaVersion): bool {
        $adjuntos = $m->attachments();

        return count($adjuntos) === 1
            && ! str_contains(json_encode($m->content()->with), $rutaVersion);
    });
});
