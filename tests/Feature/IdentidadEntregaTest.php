<?php

use App\Enums\CategoriaDocumentoExpediente;
use App\Enums\RolSistema;
use App\Models\Colaborador;
use App\Models\DocumentoExpediente;
use App\Models\VersionDocumentoExpediente;
use App\Servicios\ServicioExpediente;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Captura de una identificación oficial FALTANTE durante el flujo de entrega:
 * se guarda en el EXPEDIENTE del colaborador (no sólo en la entrega), con
 * autorización de mínimo privilegio y sin permitir duplicados.
 *
 * Las peticiones se hacen con multipart REAL (`->post()` con `UploadedFile` en
 * el array de datos + `Accept: application/json`) para reproducir el contrato
 * exacto del `fetch`/`FormData` del frontend.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    $this->colaborador = $this->datos['colaboradorA'];

    $this->subirIne = fn (UploadedFile $archivo, ?int $colaboradorId = null) => $this->post(
        '/entregas/documento-identidad/'.($colaboradorId ?? $this->colaborador->id),
        ['archivo' => $archivo],
        ['Accept' => 'application/json'],
    );
});

it('sube una INE faltante y queda visible con la MISMA consulta que usa el módulo Expediente', function () {
    $this->actingAs($this->admin);

    $this->getJson("/entregas/documento-identidad/{$this->colaborador->id}")
        ->assertOk()->assertJson(['disponible' => false]);

    ($this->subirIne)(UploadedFile::fake()->image('ine.jpg', 1000, 640))
        ->assertOk()->assertJson(['ok' => true]);

    $documento = DocumentoExpediente::query()
        ->where('colaborador_id', $this->colaborador->id)
        ->where('categoria', CategoriaDocumentoExpediente::Identificacion)
        ->first();
    expect($documento)->not->toBeNull();
    expect($documento->activo)->toBeTrue();
    expect($documento->colaborador_id)->toBe($this->colaborador->id);

    $version = VersionDocumentoExpediente::query()
        ->where('documento_expediente_id', $documento->id)->where('version', 1)->first();
    expect($version)->not->toBeNull();
    expect(Storage::disk('local')->exists($version->ruta))->toBeTrue();

    // Aparece con la consulta REAL de la pantalla del Expediente.
    $payload = app(ServicioExpediente::class)->payload($this->colaborador->fresh(), $this->admin, 'activos');
    expect(collect($payload['documentos'])->pluck('categoria'))->toContain('identificacion');

    // La entrega actual (y futuras) ya la detecta.
    $this->getJson("/entregas/documento-identidad/{$this->colaborador->id}")
        ->assertJson(['disponible' => true]);
});

it('acepta JPG, PNG y PDF; rechaza otros formatos', function () {
    $c1 = Colaborador::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->create();
    $c2 = Colaborador::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->create();
    $c3 = Colaborador::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->create();
    $this->actingAs($this->admin);

    ($this->subirIne)(UploadedFile::fake()->image('a.png'), $c1->id)->assertOk();
    ($this->subirIne)(UploadedFile::fake()->create('a.pdf', 200, 'application/pdf'), $c2->id)->assertOk();
    ($this->subirIne)(UploadedFile::fake()->create('a.txt', 10, 'text/plain'), $c3->id)->assertStatus(422);
});

it('rechaza un archivo mayor a 10 MB con un mensaje mapeable a errors.archivo', function () {
    $this->actingAs($this->admin);

    ($this->subirIne)(UploadedFile::fake()->create('ine-grande.jpg', 11000, 'image/jpeg'))
        ->assertStatus(422)
        ->assertJsonValidationErrors('archivo');
});

it('no crea una segunda identificación si el colaborador ya tiene una', function () {
    $this->actingAs($this->admin);

    ($this->subirIne)(UploadedFile::fake()->image('ine.jpg'))->assertOk();
    ($this->subirIne)(UploadedFile::fake()->image('ine2.jpg'))->assertStatus(422);

    expect(DocumentoExpediente::query()
        ->where('colaborador_id', $this->colaborador->id)
        ->where('categoria', CategoriaDocumentoExpediente::Identificacion)
        ->count())->toBe(1);
});

it('exige poder registrar entregas y acceso a la empresa del colaborador', function () {
    $encargadoAjeno = usuarioCon(RolSistema::Encargado->value, [$this->datos['empresaB']]);
    $this->actingAs($encargadoAjeno);
    ($this->subirIne)(UploadedFile::fake()->image('ine.jpg'))->assertForbidden();

    $colaboradorUser = usuarioCon(RolSistema::Colaborador->value, [$this->datos['empresaA']]);
    $this->actingAs($colaboradorUser);
    ($this->subirIne)(UploadedFile::fake()->image('ine.jpg'))->assertForbidden();
});
